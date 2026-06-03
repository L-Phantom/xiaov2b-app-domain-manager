<?php

if ($argc < 2) {
    fwrite(STDERR, "Usage: php preflight.php /path/to/site\n");
    exit(1);
}

$target = rtrim($argv[1], '/');
$result = [
    'target' => $target,
    'checks' => [
        'target_exists' => is_dir($target),
        'artisan_exists' => is_file($target . '/artisan'),
        'vendor_autoload_exists' => is_file($target . '/vendor/autoload.php'),
        'bootstrap_app_exists' => is_file($target . '/bootstrap/app.php'),
    ],
];

if (!$result['checks']['target_exists'] || !$result['checks']['artisan_exists'] || !$result['checks']['vendor_autoload_exists'] || !$result['checks']['bootstrap_app_exists']) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}

require $target . '/vendor/autoload.php';
$app = require $target . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$schema = Illuminate\Support\Facades\Schema::getFacadeRoot();
$result['checks']['app_domain_rules_table_exists'] = $schema->hasTable('v2_app_domain_rules');
$result['checks']['app_domain_rules_has_port'] = $schema->hasTable('v2_app_domain_rules') && $schema->hasColumn('v2_app_domain_rules', 'port');
$result['checks']['app_domain_groups_table_exists'] = $schema->hasTable('v2_app_domain_groups');
$result['checks']['app_domain_bindings_table_exists'] = $schema->hasTable('v2_app_domain_bindings');
$result['checks']['php_has_openssl_encrypt'] = function_exists('openssl_encrypt');
$result['checks']['php_has_posix_kill'] = function_exists('posix_kill');

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

$result['server_columns'] = [];
foreach ($serverTables as $table) {
    if (!$schema->hasTable($table)) {
        $result['server_columns'][$table] = ['table_exists' => false];
        continue;
    }
    $result['server_columns'][$table] = [
        'table_exists' => true,
        'app_show' => $schema->hasColumn($table, 'app_show'),
        'app_domain_replace' => $schema->hasColumn($table, 'app_domain_replace'),
    ];
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
