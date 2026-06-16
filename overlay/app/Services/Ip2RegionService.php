<?php

namespace App\Services;

use App\Models\SubscribeIpCache;
use Illuminate\Support\Facades\Schema;

class Ip2RegionService
{
    protected const TABLE = 'v2_subscribe_ip_cache';
    protected static $tableReady;
    protected static $searcherReady = false;
    protected static $searcher;

    public function lookup(?string $ip): ?array
    {
        $ip = trim((string) $ip);
        if ($ip === '' || !$this->isPublicIp($ip)) {
            return null;
        }

        if ($this->tableExists()) {
            $cached = SubscribeIpCache::where('ip', $ip)->first();
            if ($cached) {
                return $this->cacheRowToArray($cached);
            }
        }

        $result = $this->queryXdb($ip);
        if (!$result) {
            return null;
        }

        if ($this->tableExists()) {
            try {
                SubscribeIpCache::updateOrCreate(
                    ['ip' => $ip],
                    array_merge($result, [
                        'ip' => $ip,
                        'ip_version' => strpos($ip, ':') !== false ? 6 : 4,
                        'network_type' => $result['network_type'] ?? $this->inferNetworkType($result),
                        'ip_risk_type' => $result['ip_risk_type'] ?? null,
                        'ip_risk_score' => (int) ($result['ip_risk_score'] ?? 0),
                        'source' => 'ip2region',
                        'hit' => 1,
                        'created_at' => time(),
                        'updated_at' => time(),
                    ])
                );
            } catch (\Throwable $e) {
            }
        }

        return $result;
    }

    public function lookupMany(array $ips): array
    {
        $items = [];
        foreach ($ips as $ip) {
            $ip = trim((string) $ip);
            if ($ip === '' || isset($items[$ip])) {
                continue;
            }
            $result = $this->lookup($ip);
            if ($result) {
                $items[$ip] = $result;
            }
        }
        return $items;
    }

    public function databaseStatus(): array
    {
        $path = $this->xdbPath();
        return [
            'enabled' => is_file($path) && is_readable($path),
            'path' => $path,
            'size' => is_file($path) ? filesize($path) : 0,
            'mtime' => is_file($path) ? filemtime($path) : 0,
            'table_exists' => $this->tableExists(),
        ];
    }

    protected function queryXdb(string $ip): ?array
    {
        if (strpos($ip, ':') !== false) {
            return null;
        }

        try {
            $searcher = $this->searcher();
            if (!$searcher) {
                return null;
            }
            $raw = trim((string) $searcher->search($ip));
            if ($raw === '') {
                return null;
            }
            return $this->parseRegion($raw);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function parseRegion(string $raw): array
    {
        $parts = array_pad(explode('|', $raw), 5, '');
        return [
            'country' => $this->cleanPart($parts[0]),
            'region' => $this->cleanPart($parts[1]),
            'city' => $this->cleanPart($parts[2]),
            'isp' => $this->cleanPart($parts[3]),
            'country_code' => $this->cleanPart($parts[4]),
            'asn' => null,
            'as_name' => null,
            'network_type' => null,
            'ip_risk_type' => null,
            'ip_risk_score' => 0,
            'raw_region' => $this->safeSubstr($raw, 0, 255),
        ];
    }

    protected function cleanPart($value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '0') {
            return null;
        }
        return $value;
    }

    protected function cacheRowToArray($row): array
    {
        return [
            'country' => $row->country,
            'region' => $row->region,
            'city' => $row->city,
            'isp' => $row->isp,
            'country_code' => $row->country_code,
            'asn' => $row->asn ? (int) $row->asn : null,
            'as_name' => $row->as_name,
            'network_type' => $row->network_type ?: $this->inferNetworkType([
                'isp' => $row->isp,
                'as_name' => $row->as_name,
                'raw_region' => $row->raw_region,
            ]),
            'ip_risk_type' => $row->ip_risk_type,
            'ip_risk_score' => (int) ($row->ip_risk_score ?? 0),
            'raw_region' => $row->raw_region,
        ];
    }

    protected function inferNetworkType(array $region): ?string
    {
        $raw = implode(' ', array_filter([
            $region['isp'] ?? '',
            $region['as_name'] ?? '',
            $region['raw_region'] ?? '',
        ]));
        $text = function_exists('mb_strtolower') ? mb_strtolower($raw) : strtolower($raw);
        if ($text === '') {
            return null;
        }
        foreach (['云', 'cloud', 'hosting', 'host', 'data', '数据', '机房', 'server', 'vps', 'colo', 'dmit', 'aws', 'amazon', 'google', 'azure', 'oracle', 'digitalocean', 'linode', 'vultr', 'hetzner', 'ovh'] as $keyword) {
            if ($this->contains($text, $keyword)) {
                return 'idc';
            }
        }
        foreach (['移动', 'mobile', 'cmcc', 'cellular', 'lte', '5g', '4g'] as $keyword) {
            if ($this->contains($text, $keyword)) {
                return 'mobile';
            }
        }
        foreach (['电信', '联通', '宽带', 'broadband', 'telecom', 'unicom', 'cable', 'ftth', 'fiber'] as $keyword) {
            if ($this->contains($text, $keyword)) {
                return 'fixed';
            }
        }
        return null;
    }

    protected function safeSubstr(string $value, int $start, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, $start, $length);
        }
        return substr($value, $start, $length);
    }

    protected function contains(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        if (function_exists('mb_strpos')) {
            return mb_strpos($haystack, $needle) !== false;
        }
        return strpos($haystack, $needle) !== false;
    }

    protected function searcher()
    {
        if (self::$searcherReady) {
            return self::$searcher;
        }
        self::$searcherReady = true;
        $path = $this->xdbPath();
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        require_once base_path('/app/Support/Ip2Region/Searcher.class.php');
        if (!class_exists('\\ip2region\\xdb\\Searcher') || !class_exists('\\ip2region\\xdb\\IPv4')) {
            return null;
        }
        try {
            self::$searcher = \ip2region\xdb\Searcher::newWithVectorIndex(
                \ip2region\xdb\IPv4::default(),
                $path,
                \ip2region\xdb\Util::loadVectorIndexFromFile($path)
            );
        } catch (\Throwable $e) {
            self::$searcher = null;
        }
        return self::$searcher;
    }

    protected function xdbPath(): string
    {
        return base_path('/storage/ip2region/ip2region_v4.xdb');
    }

    protected function isPublicIp(string $ip): bool
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    protected function tableExists(): bool
    {
        if (self::$tableReady !== null) {
            return self::$tableReady;
        }
        try {
            self::$tableReady = Schema::hasTable(self::TABLE);
        } catch (\Throwable $e) {
            self::$tableReady = false;
        }
        return self::$tableReady;
    }
}
