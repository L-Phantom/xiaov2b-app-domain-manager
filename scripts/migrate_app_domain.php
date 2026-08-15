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

if (!$schema->hasTable('v2_app_domain_assignments')) {
    $actions[] = 'create table v2_app_domain_assignments';
    if ($mode === '--apply') {
        $sql = file_get_contents(dirname(__DIR__) . '/sql/app_domain_assignments.sql');
        $db->statement($sql);
    }
}

if (!$schema->hasTable('v2_app_domain_replace_batches')) {
    $actions[] = 'create table v2_app_domain_replace_batches';
    if ($mode === '--apply') {
        $sql = file_get_contents(dirname(__DIR__) . '/sql/app_domain_replace_batches.sql');
        $db->statement($sql);
    }
}

$groupColumns = [
    'hide_matched_nodes' => 'ALTER TABLE `v2_app_domain_groups` ADD COLUMN `hide_matched_nodes` tinyint(1) NOT NULL DEFAULT 0 AFTER `plan_ids`',
    'assignment_only' => 'ALTER TABLE `v2_app_domain_groups` ADD COLUMN `assignment_only` tinyint(1) NOT NULL DEFAULT 0 AFTER `hide_matched_nodes`',
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
    'app_domain_assignments_table_exists' => $schema->hasTable('v2_app_domain_assignments') || ($mode === '--apply' && in_array('create table v2_app_domain_assignments', $actions, true)),
    'app_domain_replace_batches_table_exists' => $schema->hasTable('v2_app_domain_replace_batches') || ($mode === '--apply' && in_array('create table v2_app_domain_replace_batches', $actions, true)),
    'app_domain_groups_has_distribution_columns' => ($schema->hasTable('v2_app_domain_groups') || $mode === '--apply')
        && !array_filter(array_keys($groupColumns), function ($column) use ($schema, $actions) {
            return !$schema->hasColumn('v2_app_domain_groups', $column) && !in_array("add column v2_app_domain_groups.{$column}", $actions, true);
        }),
    'app_domain_groups_has_assignment_only' => ($schema->hasTable('v2_app_domain_groups') || $mode === '--apply')
        && ($schema->hasColumn('v2_app_domain_groups', 'assignment_only') || in_array('add column v2_app_domain_groups.assignment_only', $actions, true)),
    'server_columns' => $serverColumnStatus,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
