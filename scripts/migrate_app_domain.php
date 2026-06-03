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

$result = [
    'mode' => $mode,
    'target' => $target,
    'actions' => $actions,
    'app_domain_rules_table_exists' => $schema->hasTable('v2_app_domain_rules') || ($mode === '--apply' && in_array('create table v2_app_domain_rules', $actions, true)),
    'app_domain_rules_has_port' => $schema->hasTable('v2_app_domain_rules') ? $schema->hasColumn('v2_app_domain_rules', 'port') || ($mode === '--apply' && in_array('add column v2_app_domain_rules.port', $actions, true)) : false,
    'app_domain_groups_table_exists' => $schema->hasTable('v2_app_domain_groups') || ($mode === '--apply' && in_array('create table v2_app_domain_groups', $actions, true)),
    'app_domain_bindings_table_exists' => $schema->hasTable('v2_app_domain_bindings') || ($mode === '--apply' && in_array('create table v2_app_domain_bindings', $actions, true)),
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
