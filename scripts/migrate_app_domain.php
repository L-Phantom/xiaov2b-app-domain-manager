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

$result = [
    'mode' => $mode,
    'target' => $target,
    'actions' => $actions,
    'app_domain_rules_table_exists' => $schema->hasTable('v2_app_domain_rules') || ($mode === '--apply' && in_array('create table v2_app_domain_rules', $actions, true)),
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
