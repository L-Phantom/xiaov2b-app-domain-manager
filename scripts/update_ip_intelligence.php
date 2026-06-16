<?php

if ($argc < 2) {
    fwrite(STDERR, "Usage: php update_ip_intelligence.php /path/to/site [csv-file] [--dry-run|--apply|--export-missing]\n");
    exit(1);
}

$target = rtrim($argv[1], '/');
$csvFile = null;
$mode = '--dry-run';
foreach (array_slice($argv, 2) as $arg) {
    if (in_array($arg, ['--dry-run', '--apply', '--export-missing'], true)) {
        $mode = $arg;
    } elseif ($csvFile === null) {
        $csvFile = $arg;
    }
}

if (!is_dir($target) || !is_file($target . '/artisan')) {
    fwrite(STDERR, "Target site not found or artisan missing: {$target}\n");
    exit(1);
}
if ($mode !== '--export-missing' && (!$csvFile || !is_file($csvFile) || !is_readable($csvFile))) {
    fwrite(STDERR, "Readable CSV file is required unless --export-missing is used\n");
    exit(1);
}

require $target . '/vendor/autoload.php';
$app = require $target . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$schema = Illuminate\Support\Facades\Schema::getFacadeRoot();
$db = Illuminate\Support\Facades\DB::connection();

if (!$schema->hasTable('v2_subscribe_ip_cache')) {
    fwrite(STDERR, "Table v2_subscribe_ip_cache does not exist. Run scripts/migrate_app_domain.php first.\n");
    exit(1);
}

if ($mode === '--export-missing') {
    exportMissingIps($schema, $db);
    exit(0);
}

$result = importCsv($db, $csvFile, $mode);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

function exportMissingIps($schema, $db): void
{
    if (!$schema->hasTable('v2_subscribe_access_logs')) {
        return;
    }

    $rows = $db->table('v2_subscribe_access_logs as l')
        ->leftJoin('v2_subscribe_ip_cache as c', 'l.client_ip', '=', 'c.ip')
        ->select('l.client_ip')
        ->whereNotNull('l.client_ip')
        ->where(function ($query) {
            $query->whereNull('c.ip')
                ->orWhere(function ($sub) {
                    $sub->whereNull('c.asn')
                        ->whereNull('c.as_name')
                        ->whereNull('c.ip_risk_type');
                });
        })
        ->groupBy('l.client_ip')
        ->orderByRaw('MAX(l.created_at) DESC')
        ->limit(10000)
        ->pluck('client_ip');

    echo "ip\n";
    foreach ($rows as $ip) {
        $ip = trim((string) $ip);
        if (isPublicIp($ip)) {
            echo $ip . "\n";
        }
    }
}

function importCsv($db, string $csvFile, string $mode): array
{
    $handle = fopen($csvFile, 'r');
    if (!$handle) {
        throw new RuntimeException("Unable to open CSV: {$csvFile}");
    }

    $header = null;
    $stats = [
        'mode' => $mode,
        'csv' => $csvFile,
        'seen' => 0,
        'valid' => 0,
        'updated' => 0,
        'skipped' => 0,
        'errors' => [],
    ];

    while (($row = fgetcsv($handle)) !== false) {
        if ($header === null) {
            $header = normalizeHeader($row);
            continue;
        }

        $stats['seen']++;
        $item = rowToAssoc($header, $row);
        $ip = trim((string) ($item['ip'] ?? ''));
        if (!isPublicIp($ip)) {
            $stats['skipped']++;
            continue;
        }

        $payload = buildPayload($ip, $item);
        if (!$payload) {
            $stats['skipped']++;
            continue;
        }

        $stats['valid']++;
        if ($mode === '--apply') {
            $existing = $db->table('v2_subscribe_ip_cache')->where('ip', $ip)->first();
            if ($existing) {
                unset($payload['created_at']);
                $db->table('v2_subscribe_ip_cache')->where('ip', $ip)->update($payload);
            } else {
                $db->table('v2_subscribe_ip_cache')->insert($payload);
            }
            $stats['updated']++;
        }
    }
    fclose($handle);

    return $stats;
}

function normalizeHeader(array $row): array
{
    $aliases = [
        'ip_address' => 'ip',
        'address' => 'ip',
        'query' => 'ip',
        'as_number' => 'asn',
        'asn_number' => 'asn',
        'as' => 'asn',
        'org' => 'as_name',
        'organization' => 'as_name',
        'as_org' => 'as_name',
        'asn_name' => 'as_name',
        'type' => 'network_type',
        'net_type' => 'network_type',
        'risk_type' => 'ip_risk_type',
        'threat_type' => 'ip_risk_type',
        'proxy_type' => 'ip_risk_type',
        'risk_score' => 'ip_risk_score',
        'threat_score' => 'ip_risk_score',
        'score' => 'ip_risk_score',
        'provider' => 'source',
        'source_name' => 'source',
    ];

    return array_map(function ($name) use ($aliases) {
        $name = strtolower(trim((string) $name));
        $name = preg_replace('/[^a-z0-9_]+/', '_', $name);
        $name = trim((string) $name, '_');
        return $aliases[$name] ?? $name;
    }, $row);
}

function rowToAssoc(array $header, array $row): array
{
    $item = [];
    foreach ($header as $index => $key) {
        if ($key === '') {
            continue;
        }
        $item[$key] = $row[$index] ?? null;
    }
    return $item;
}

function buildPayload(string $ip, array $item): array
{
    $now = time();
    $asn = normalizeAsn($item['asn'] ?? null);
    $asName = limitString($item['as_name'] ?? null, 255);
    $networkType = normalizeNetworkType($item['network_type'] ?? null);
    $riskType = normalizeRiskType($item['ip_risk_type'] ?? null);
    $riskScore = normalizeRiskScore($item['ip_risk_score'] ?? null, $riskType);
    $source = limitString($item['source'] ?? 'manual_csv', 32) ?: 'manual_csv';

    $payload = [
        'ip' => $ip,
        'ip_version' => strpos($ip, ':') !== false ? 6 : 4,
        'source' => $source,
        'hit' => 1,
        'updated_at' => $now,
    ];

    foreach ([
        'country' => 64,
        'region' => 128,
        'city' => 128,
        'isp' => 128,
        'country_code' => 16,
        'raw_region' => 255,
    ] as $field => $length) {
        if (array_key_exists($field, $item)) {
            $payload[$field] = limitString($item[$field], $length);
        }
    }

    if ($asn !== null) {
        $payload['asn'] = $asn;
    }
    if ($asName !== null) {
        $payload['as_name'] = $asName;
    }
    if ($networkType !== null) {
        $payload['network_type'] = $networkType;
    }
    if ($riskType !== null) {
        $payload['ip_risk_type'] = $riskType;
    }
    if ($riskScore !== null) {
        $payload['ip_risk_score'] = $riskScore;
    }

    $payload['created_at'] = $now;

    return $payload;
}

function normalizeAsn($value): ?int
{
    $value = strtoupper(trim((string) $value));
    $value = preg_replace('/^AS/', '', $value);
    if ($value === '' || !ctype_digit($value)) {
        return null;
    }
    $asn = (int) $value;
    return $asn > 0 ? $asn : null;
}

function normalizeNetworkType($value): ?string
{
    $value = strtolower(trim((string) $value));
    if ($value === '') {
        return null;
    }
    $map = [
        'hosting' => 'idc',
        'datacenter' => 'idc',
        'data_center' => 'idc',
        'dc' => 'idc',
        'server' => 'idc',
        'business' => 'fixed',
        'residential' => 'fixed',
        'home' => 'fixed',
        'broadband' => 'fixed',
        'cellular' => 'mobile',
        'wireless' => 'mobile',
    ];
    $value = $map[$value] ?? $value;
    return in_array($value, ['idc', 'fixed', 'mobile'], true) ? $value : null;
}

function normalizeRiskType($value): ?string
{
    $value = strtolower(trim((string) $value));
    if ($value === '') {
        return null;
    }
    $map = [
        'anonymous' => 'proxy',
        'public_proxy' => 'proxy',
        'web_proxy' => 'proxy',
        'open_proxy' => 'proxy',
        'hosting' => 'proxy',
        'crawler' => 'bot',
        'spider' => 'bot',
        'scanner' => 'bot',
    ];
    $value = $map[$value] ?? $value;
    return in_array($value, ['proxy', 'vpn', 'tor', 'bot'], true) ? $value : null;
}

function normalizeRiskScore($value, ?string $riskType): ?int
{
    if ($value === null || $value === '') {
        return $riskType ? 80 : null;
    }
    $score = (int) $value;
    return max(0, min(100, $score));
}

function limitString($value, int $length): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $length);
    }
    return substr($value, 0, $length);
}

function isPublicIp(string $ip): bool
{
    return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}
