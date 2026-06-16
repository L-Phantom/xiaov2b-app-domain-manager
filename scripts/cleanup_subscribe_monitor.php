<?php

if ($argc < 2) {
    fwrite(STDERR, "Usage: php cleanup_subscribe_monitor.php /path/to/site [--dry-run|--apply] [--access-log-days=180] [--snapshot-days=365] [--ip-cache-days=90]\n");
    exit(1);
}

$target = rtrim($argv[1], '/');
$mode = '--dry-run';
$accessLogDays = 180;
$snapshotDays = 365;
$ipCacheDays = 90;

foreach (array_slice($argv, 2) as $arg) {
    if (in_array($arg, ['--dry-run', '--apply'], true)) {
        $mode = $arg;
    } elseif (strpos($arg, '--access-log-days=') === 0) {
        $accessLogDays = max(1, (int) substr($arg, 18));
    } elseif (strpos($arg, '--snapshot-days=') === 0) {
        $snapshotDays = max(1, (int) substr($arg, 16));
    } elseif (strpos($arg, '--ip-cache-days=') === 0) {
        $ipCacheDays = max(1, (int) substr($arg, 16));
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
$now = time();
$targets = [
    'v2_subscribe_access_logs' => [
        'days' => $accessLogDays,
        'column' => 'created_at',
        'description' => '原始拉取记录',
    ],
    'v2_subscribe_risk_snapshots' => [
        'days' => $snapshotDays,
        'column' => 'snapshot_at',
        'description' => '风险画像快照',
    ],
    'v2_subscribe_ip_cache' => [
        'days' => $ipCacheDays,
        'column' => 'updated_at',
        'description' => 'IP 情报缓存',
    ],
];

$result = [
    'mode' => $mode,
    'target' => $target,
    'retention' => [
        'access_log_days' => $accessLogDays,
        'risk_snapshot_days' => $snapshotDays,
        'ip_cache_days' => $ipCacheDays,
        'disposition_logs' => '长期保留',
    ],
    'tables' => [],
];

foreach ($targets as $table => $meta) {
    if (!$schema->hasTable($table)) {
        $result['tables'][$table] = [
            'exists' => false,
            'deleted' => 0,
        ];
        continue;
    }
    $cutoff = $now - $meta['days'] * 86400;
    $query = $db->table($table)->where($meta['column'], '<', $cutoff);
    $count = (int) (clone $query)->count();
    $deleted = 0;
    if ($mode === '--apply' && $count > 0) {
        $deleted = (int) $query->delete();
    }
    $result['tables'][$table] = [
        'exists' => true,
        'description' => $meta['description'],
        'cutoff' => $cutoff,
        'matched' => $count,
        'deleted' => $deleted,
    ];
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
