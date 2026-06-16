<?php

if ($argc < 2) {
    fwrite(STDERR, "Usage: php update_free_ip_intelligence.php /path/to/site [--dry-run|--apply] [--limit=10000] [--cache-dir=/tmp/xiaov2b-ip-intel] [--skip-download]\n");
    exit(1);
}

$target = rtrim($argv[1], '/');
$mode = '--dry-run';
$limit = 10000;
$cacheDir = sys_get_temp_dir() . '/xiaov2b-ip-intel';
$skipDownload = false;

foreach (array_slice($argv, 2) as $arg) {
    if (in_array($arg, ['--dry-run', '--apply'], true)) {
        $mode = $arg;
    } elseif (strpos($arg, '--limit=') === 0) {
        $limit = max(1, min(50000, (int) substr($arg, 8)));
    } elseif (strpos($arg, '--cache-dir=') === 0) {
        $cacheDir = rtrim(substr($arg, 12), '/');
    } elseif ($arg === '--skip-download') {
        $skipDownload = true;
    }
}

if (!is_dir($target) || !is_file($target . '/artisan')) {
    fwrite(STDERR, "Target site not found or artisan missing: {$target}\n");
    exit(1);
}

require $target . '/vendor/autoload.php';
$app = require $target . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$schema = Illuminate\Support\Facades\Schema::getFacadeRoot();
$db = Illuminate\Support\Facades\DB::connection();
if (!$schema->hasTable('v2_subscribe_ip_cache') || !$schema->hasTable('v2_subscribe_access_logs')) {
    fwrite(STDERR, "Required tables are missing. Run scripts/migrate_app_domain.php first.\n");
    exit(1);
}

ensureDir($cacheDir);
$sources = freeSources();
if (!$skipDownload) {
    foreach ($sources as $source) {
        downloadIfNeeded($source['url'], $cacheDir . '/' . $source['file']);
    }
}

$targets = loadTargetIps($db, $limit);
$result = [
    'mode' => $mode,
    'target' => $target,
    'cache_dir' => $cacheDir,
    'target_ips' => count($targets),
    'matched_asn' => 0,
    'matched_datacenter' => 0,
    'matched_vpn' => 0,
    'updated' => 0,
    'sources' => array_values(array_map(function ($source) {
        return $source['name'];
    }, $sources)),
];

$intel = [];
foreach ($targets as $ip) {
    $intel[$ip] = [
        'ip' => $ip,
        'ip_version' => strpos($ip, ':') !== false ? 6 : 4,
        'source' => 'free_ip_intelligence',
        'hit' => 1,
        'updated_at' => time(),
    ];
}

applyAsnIntel($cacheDir . '/ip2asn-v4.tsv.gz', $targets, $intel, $result);
applyAsnIntel($cacheDir . '/ip2asn-v6.tsv.gz', $targets, $intel, $result);
applyCidrIntel($cacheDir . '/x4bnet-datacenter-ipv4.txt', $targets, $intel, 'idc', null, $result);
applyCidrIntel($cacheDir . '/x4bnet-vpn-ipv4.txt', $targets, $intel, 'idc', 'vpn', $result);

if ($mode === '--apply') {
    foreach ($intel as $ip => $payload) {
        if (!hasUsefulIntel($payload)) {
            continue;
        }
        $existing = $db->table('v2_subscribe_ip_cache')->where('ip', $ip)->first();
        if ($existing) {
            unset($payload['created_at']);
            $db->table('v2_subscribe_ip_cache')->where('ip', $ip)->update($payload);
        } else {
            $payload['created_at'] = time();
            $db->table('v2_subscribe_ip_cache')->insert($payload);
        }
        $result['updated']++;
    }
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

function freeSources(): array
{
    return [
        ['name' => 'IPtoASN IPv4', 'file' => 'ip2asn-v4.tsv.gz', 'url' => 'https://iptoasn.com/data/ip2asn-v4.tsv.gz'],
        ['name' => 'IPtoASN IPv6', 'file' => 'ip2asn-v6.tsv.gz', 'url' => 'https://iptoasn.com/data/ip2asn-v6.tsv.gz'],
        ['name' => 'X4BNet Datacenter IPv4', 'file' => 'x4bnet-datacenter-ipv4.txt', 'url' => 'https://raw.githubusercontent.com/X4BNet/lists_vpn/main/output/datacenter/ipv4.txt'],
        ['name' => 'X4BNet VPN IPv4', 'file' => 'x4bnet-vpn-ipv4.txt', 'url' => 'https://raw.githubusercontent.com/X4BNet/lists_vpn/main/output/vpn/ipv4.txt'],
    ];
}

function ensureDir(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("Unable to create cache dir: {$dir}");
    }
}

function downloadIfNeeded(string $url, string $file): void
{
    $fresh = is_file($file) && filesize($file) > 0 && filemtime($file) >= time() - 86400;
    if ($fresh) {
        return;
    }

    $tmp = $file . '.tmp';
    if (function_exists('curl_init')) {
        $fp = fopen($tmp, 'w');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => 'xiaov2b-app-domain-manager/1.0',
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        if ($ok && $code >= 200 && $code < 300 && filesize($tmp) > 0) {
            rename($tmp, $file);
            return;
        }
        @unlink($tmp);
    }

    $cmd = null;
    if (commandExists('curl')) {
        $cmd = 'curl -L --fail --connect-timeout 15 --max-time 120 -A xiaov2b-app-domain-manager/1.0 -o ' . escapeshellarg($tmp) . ' ' . escapeshellarg($url);
    } elseif (commandExists('wget')) {
        $cmd = 'wget -T 120 -O ' . escapeshellarg($tmp) . ' ' . escapeshellarg($url);
    }
    if ($cmd) {
        exec($cmd, $out, $code);
        if ($code === 0 && is_file($tmp) && filesize($tmp) > 0) {
            rename($tmp, $file);
            return;
        }
        @unlink($tmp);
    }

    throw new RuntimeException("Unable to download {$url}");
}

function commandExists(string $command): bool
{
    exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null', $out, $code);
    return $code === 0;
}

function loadTargetIps($db, int $limit): array
{
    $rows = $db->table('v2_subscribe_access_logs')
        ->select('client_ip')
        ->whereNotNull('client_ip')
        ->groupBy('client_ip')
        ->orderByRaw('MAX(created_at) DESC')
        ->limit($limit)
        ->pluck('client_ip');

    $ips = [];
    foreach ($rows as $ip) {
        $ip = trim((string) $ip);
        if (isPublicIp($ip)) {
            $ips[$ip] = $ip;
        }
    }
    return array_values($ips);
}

function applyAsnIntel(string $file, array $targets, array &$intel, array &$result): void
{
    if (!is_file($file)) {
        return;
    }
    $handle = gzopen($file, 'r');
    if (!$handle) {
        return;
    }
    $remaining = [];
    foreach ($targets as $ip) {
        $packed = inet_pton($ip);
        if ($packed !== false) {
            $remaining[$ip] = $packed;
        }
    }
    while (!gzeof($handle) && $remaining) {
        $line = trim((string) gzgets($handle));
        if ($line === '') {
            continue;
        }
        $parts = explode("\t", $line, 5);
        if (count($parts) < 5) {
            continue;
        }
        [$start, $end, $asn, $countryCode, $asName] = $parts;
        $startPacked = inet_pton($start);
        $endPacked = inet_pton($end);
        if ($startPacked === false || $endPacked === false) {
            continue;
        }
        foreach ($remaining as $ip => $packed) {
            if (strlen($packed) !== strlen($startPacked)) {
                continue;
            }
            if (binaryCompare($packed, $startPacked) >= 0 && binaryCompare($packed, $endPacked) <= 0) {
                $asnValue = normalizeAsn($asn);
                if ($asnValue) {
                    $intel[$ip]['asn'] = $asnValue;
                    $intel[$ip]['as_name'] = limitString($asName, 255);
                    $intel[$ip]['country_code'] = limitString($countryCode, 16);
                    $result['matched_asn']++;
                }
                unset($remaining[$ip]);
            }
        }
    }
    gzclose($handle);
}

function applyCidrIntel(string $file, array $targets, array &$intel, ?string $networkType, ?string $riskType, array &$result): void
{
    if (!is_file($file)) {
        return;
    }
    $cidrs = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($targets as $ip) {
        foreach ($cidrs as $cidr) {
            $cidr = trim((string) $cidr);
            if ($cidr === '' || $cidr[0] === '#') {
                continue;
            }
            if (!cidrContains($cidr, $ip)) {
                continue;
            }
            if ($networkType) {
                $intel[$ip]['network_type'] = $networkType;
                if ($networkType === 'idc') {
                    $result['matched_datacenter']++;
                }
            }
            if ($riskType) {
                $intel[$ip]['ip_risk_type'] = $riskType;
                $intel[$ip]['ip_risk_score'] = max((int) ($intel[$ip]['ip_risk_score'] ?? 0), 80);
                $result['matched_vpn']++;
            }
            break;
        }
    }
}

function cidrContains(string $cidr, string $ip): bool
{
    if (strpos($cidr, '/') === false) {
        return $cidr === $ip;
    }
    [$network, $prefix] = explode('/', $cidr, 2);
    $networkPacked = inet_pton($network);
    $ipPacked = inet_pton($ip);
    if ($networkPacked === false || $ipPacked === false || strlen($networkPacked) !== strlen($ipPacked)) {
        return false;
    }
    $prefix = (int) $prefix;
    $bytes = intdiv($prefix, 8);
    $bits = $prefix % 8;
    if ($bytes > 0 && substr($networkPacked, 0, $bytes) !== substr($ipPacked, 0, $bytes)) {
        return false;
    }
    if ($bits === 0) {
        return true;
    }
    $mask = (0xff << (8 - $bits)) & 0xff;
    return (ord($networkPacked[$bytes]) & $mask) === (ord($ipPacked[$bytes]) & $mask);
}

function binaryCompare(string $a, string $b): int
{
    $len = min(strlen($a), strlen($b));
    for ($i = 0; $i < $len; $i++) {
        $diff = ord($a[$i]) <=> ord($b[$i]);
        if ($diff !== 0) {
            return $diff;
        }
    }
    return strlen($a) <=> strlen($b);
}

function hasUsefulIntel(array $payload): bool
{
    return isset($payload['asn'])
        || !empty($payload['as_name'])
        || !empty($payload['network_type'])
        || !empty($payload['ip_risk_type']);
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
