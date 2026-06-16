<?php

if ($argc < 2) {
    fwrite(STDERR, "Usage: php migrate_app_domain.php /path/to/site [--dry-run|--apply]\n");
    exit(1);
}

$target = rtrim($argv[1], '/');
$mode = $argv[2] ?? '--dry-run';
if (!in_array($mode, ['--dry-run', '--apply'], true)) {
    fwrite(STDERR, "Mode must be --dry-run or --apply\n");
    exit(1);
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
$actions = [];
$serverTables = [
    'v2_server_shadowsocks',
    'v2_server_vmess',
    'v2_server_trojan',
    'v2_server_vless',
    'v2_server_hysteria',
    'v2_server_tuic',
    'v2_server_anytls',
    'v2_server_v2node',
];

if (!$schema->hasTable('v2_app_domain_rules')) {
    $actions[] = 'create table v2_app_domain_rules';
    if ($mode === '--apply') {
        $sql = file_get_contents(dirname(__DIR__) . '/sql/app_domain_rules.sql');
        $db->statement($sql);
    }
}

if (($schema->hasTable('v2_app_domain_rules') || $mode === '--apply') && !$schema->hasColumn('v2_app_domain_rules', 'port')) {
    $actions[] = 'add column v2_app_domain_rules.port';
    if ($mode === '--apply') {
        $db->statement('ALTER TABLE `v2_app_domain_rules` ADD COLUMN `port` int unsigned DEFAULT NULL AFTER `domain`');
    }
}

if (!$schema->hasTable('v2_app_domain_groups')) {
    $actions[] = 'create table v2_app_domain_groups';
    if ($mode === '--apply') {
        $sql = file_get_contents(dirname(__DIR__) . '/sql/app_domain_groups.sql');
        $db->statement($sql);
    }
}

if (!$schema->hasTable('v2_app_domain_bindings')) {
    $actions[] = 'create table v2_app_domain_bindings';
    if ($mode === '--apply') {
        $sql = file_get_contents(dirname(__DIR__) . '/sql/app_domain_bindings.sql');
        $db->statement($sql);
    }
}

if (!$schema->hasTable('v2_subscribe_access_logs')) {
    $actions[] = 'create table v2_subscribe_access_logs';
    if ($mode === '--apply') {
        $sql = file_get_contents(dirname(__DIR__) . '/sql/subscribe_access_logs.sql');
        $db->statement($sql);
    }
}

if (!$schema->hasTable('v2_subscribe_ip_cache')) {
    $actions[] = 'create table v2_subscribe_ip_cache';
    if ($mode === '--apply') {
        $sql = file_get_contents(dirname(__DIR__) . '/sql/subscribe_ip_cache.sql');
        $db->statement($sql);
    }
}

if (!$schema->hasTable('v2_subscribe_dispositions')) {
    $actions[] = 'create table v2_subscribe_dispositions';
    if ($mode === '--apply') {
        $sql = file_get_contents(dirname(__DIR__) . '/sql/subscribe_dispositions.sql');
        $db->statement($sql);
    }
}

if (!$schema->hasTable('v2_subscribe_disposition_logs')) {
    $actions[] = 'create table v2_subscribe_disposition_logs';
    if ($mode === '--apply') {
        $sql = file_get_contents(dirname(__DIR__) . '/sql/subscribe_disposition_logs.sql');
        $db->statement($sql);
    }
}

if (!$schema->hasTable('v2_subscribe_risk_snapshots')) {
    $actions[] = 'create table v2_subscribe_risk_snapshots';
    if ($mode === '--apply') {
        $sql = file_get_contents(dirname(__DIR__) . '/sql/subscribe_risk_snapshots.sql');
        $db->statement($sql);
    }
}

$ipCacheColumns = [
    'asn' => 'ALTER TABLE `v2_subscribe_ip_cache` ADD COLUMN `asn` int unsigned DEFAULT NULL AFTER `country_code`',
    'as_name' => 'ALTER TABLE `v2_subscribe_ip_cache` ADD COLUMN `as_name` varchar(255) DEFAULT NULL AFTER `asn`',
    'network_type' => 'ALTER TABLE `v2_subscribe_ip_cache` ADD COLUMN `network_type` varchar(32) DEFAULT NULL AFTER `as_name`',
    'ip_risk_type' => 'ALTER TABLE `v2_subscribe_ip_cache` ADD COLUMN `ip_risk_type` varchar(32) DEFAULT NULL AFTER `network_type`',
    'ip_risk_score' => 'ALTER TABLE `v2_subscribe_ip_cache` ADD COLUMN `ip_risk_score` tinyint unsigned NOT NULL DEFAULT 0 AFTER `ip_risk_type`',
];
if ($schema->hasTable('v2_subscribe_ip_cache') || $mode === '--apply') {
    foreach ($ipCacheColumns as $column => $sql) {
        if (!$schema->hasColumn('v2_subscribe_ip_cache', $column)) {
            $actions[] = "add column v2_subscribe_ip_cache.{$column}";
            if ($mode === '--apply') {
                $db->statement($sql);
            }
        }
    }
    foreach ([
        'idx_asn' => 'ALTER TABLE `v2_subscribe_ip_cache` ADD KEY `idx_asn` (`asn`)',
        'idx_network_type' => 'ALTER TABLE `v2_subscribe_ip_cache` ADD KEY `idx_network_type` (`network_type`)',
        'idx_ip_risk_type' => 'ALTER TABLE `v2_subscribe_ip_cache` ADD KEY `idx_ip_risk_type` (`ip_risk_type`)',
    ] as $index => $sql) {
        try {
            $exists = (bool) $db->selectOne("SHOW INDEX FROM `v2_subscribe_ip_cache` WHERE Key_name = ?", [$index]);
        } catch (Throwable $e) {
            $exists = true;
        }
        if (!$exists) {
            $actions[] = "add index v2_subscribe_ip_cache.{$index}";
            if ($mode === '--apply') {
                $db->statement($sql);
            }
        }
    }
}

$groupColumns = [
    'risk_levels' => 'ALTER TABLE `v2_app_domain_groups` ADD COLUMN `risk_levels` longtext DEFAULT NULL AFTER `plan_ids`',
    'disposition_statuses' => 'ALTER TABLE `v2_app_domain_groups` ADD COLUMN `disposition_statuses` longtext DEFAULT NULL AFTER `risk_levels`',
    'hide_matched_nodes' => 'ALTER TABLE `v2_app_domain_groups` ADD COLUMN `hide_matched_nodes` tinyint(1) NOT NULL DEFAULT 0 AFTER `disposition_statuses`',
];
if ($schema->hasTable('v2_app_domain_groups') || $mode === '--apply') {
    foreach ($groupColumns as $column => $sql) {
        if (!$schema->hasColumn('v2_app_domain_groups', $column)) {
            $actions[] = "add column v2_app_domain_groups.{$column}";
            if ($mode === '--apply') {
                $db->statement($sql);
            }
        }
    }
}

$serverColumnStatus = [];
foreach ($serverTables as $table) {
    if (!$schema->hasTable($table)) {
        $serverColumnStatus[$table] = [
            'table_exists' => false,
            'app_show' => false,
            'app_domain_replace' => false,
        ];
        continue;
    }

    foreach (['app_show', 'app_domain_replace'] as $column) {
        if (!$schema->hasColumn($table, $column)) {
            $actions[] = "add column {$table}.{$column}";
            if ($mode === '--apply') {
                $db->statement("ALTER TABLE `{$table}` ADD COLUMN `{$column}` tinyint(1) NOT NULL DEFAULT 1");
            }
        }
    }

    $serverColumnStatus[$table] = [
        'table_exists' => true,
        'app_show' => $schema->hasColumn($table, 'app_show') || ($mode === '--apply' && in_array("add column {$table}.app_show", $actions, true)),
        'app_domain_replace' => $schema->hasColumn($table, 'app_domain_replace') || ($mode === '--apply' && in_array("add column {$table}.app_domain_replace", $actions, true)),
    ];
}

$result = [
    'mode' => $mode,
    'target' => $target,
    'actions' => $actions,
    'app_domain_rules_table_exists' => $schema->hasTable('v2_app_domain_rules') || ($mode === '--apply' && in_array('create table v2_app_domain_rules', $actions, true)),
    'app_domain_rules_has_port' => $schema->hasTable('v2_app_domain_rules') ? $schema->hasColumn('v2_app_domain_rules', 'port') || ($mode === '--apply' && in_array('add column v2_app_domain_rules.port', $actions, true)) : false,
    'app_domain_groups_table_exists' => $schema->hasTable('v2_app_domain_groups') || ($mode === '--apply' && in_array('create table v2_app_domain_groups', $actions, true)),
    'app_domain_bindings_table_exists' => $schema->hasTable('v2_app_domain_bindings') || ($mode === '--apply' && in_array('create table v2_app_domain_bindings', $actions, true)),
    'subscribe_access_logs_table_exists' => $schema->hasTable('v2_subscribe_access_logs') || ($mode === '--apply' && in_array('create table v2_subscribe_access_logs', $actions, true)),
    'subscribe_ip_cache_table_exists' => $schema->hasTable('v2_subscribe_ip_cache') || ($mode === '--apply' && in_array('create table v2_subscribe_ip_cache', $actions, true)),
    'subscribe_dispositions_table_exists' => $schema->hasTable('v2_subscribe_dispositions') || ($mode === '--apply' && in_array('create table v2_subscribe_dispositions', $actions, true)),
    'subscribe_disposition_logs_table_exists' => $schema->hasTable('v2_subscribe_disposition_logs') || ($mode === '--apply' && in_array('create table v2_subscribe_disposition_logs', $actions, true)),
    'subscribe_risk_snapshots_table_exists' => $schema->hasTable('v2_subscribe_risk_snapshots') || ($mode === '--apply' && in_array('create table v2_subscribe_risk_snapshots', $actions, true)),
    'subscribe_ip_cache_has_intelligence_columns' => ($schema->hasTable('v2_subscribe_ip_cache') || $mode === '--apply')
        && !array_filter(array_keys($ipCacheColumns), function ($column) use ($schema, $actions) {
            return !$schema->hasColumn('v2_subscribe_ip_cache', $column) && !in_array("add column v2_subscribe_ip_cache.{$column}", $actions, true);
        }),
    'app_domain_groups_has_risk_linkage_columns' => ($schema->hasTable('v2_app_domain_groups') || $mode === '--apply')
        && !array_filter(array_keys($groupColumns), function ($column) use ($schema, $actions) {
            return !$schema->hasColumn('v2_app_domain_groups', $column) && !in_array("add column v2_app_domain_groups.{$column}", $actions, true);
        }),
    'server_columns' => $serverColumnStatus,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
