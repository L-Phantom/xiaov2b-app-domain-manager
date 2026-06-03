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
$result['checks']['app_meta_template_exists'] = is_file($target . '/resources/rules/app.meta.clash.yaml');
$result['checks']['admin_asset_manager_exists'] = is_file($target . '/public/assets/admin/app-domain-manager.js');
$result['checks']['admin_shell_exists'] = is_file($target . '/resources/views/admin.blade.php');
$result['checks']['v2_app_route_exists'] = is_file($target . '/app/Http/Routes/V2/AppRoute.php');
$result['checks']['v2_app_controller_exists'] = is_file($target . '/app/Http/Controllers/V2/App/AppController.php');
$result['checks']['v2_app_base_controller_exists'] = is_file($target . '/app/Http/Controllers/V2/App/BaseController.php');
$result['checks']['v2_app_auth_controller_exists'] = is_file($target . '/app/Http/Controllers/V2/App/AuthController.php');
$result['checks']['v2_app_node_controller_exists'] = is_file($target . '/app/Http/Controllers/V2/App/NodeController.php');
$result['checks']['v2_app_notice_controller_exists'] = is_file($target . '/app/Http/Controllers/V2/App/NoticeController.php');
$result['checks']['v2_app_order_controller_exists'] = is_file($target . '/app/Http/Controllers/V2/App/OrderController.php');
$result['checks']['v2_app_plan_controller_exists'] = is_file($target . '/app/Http/Controllers/V2/App/PlanController.php');
$result['checks']['v2_app_user_controller_exists'] = is_file($target . '/app/Http/Controllers/V2/App/UserController.php');
$result['checks']['app_user_middleware_exists'] = is_file($target . '/app/Http/Middleware/AppUser.php');

$kernel = $target . '/app/Http/Kernel.php';
$result['checks']['kernel_has_app_user_alias'] = is_file($kernel) && strpos((string) file_get_contents($kernel), "'app.user'") !== false;

$v2Route = $target . '/app/Http/Routes/V2/AppRoute.php';
$v2RouteContent = is_file($v2Route) ? (string) file_get_contents($v2Route) : '';
$result['v2_app_routes'] = [
    'bootstrap' => strpos($v2RouteContent, "AppController@bootstrap") !== false,
    'capabilities' => strpos($v2RouteContent, "AppController@capabilities") !== false,
    'client_config' => strpos($v2RouteContent, "AppController@clientConfig") !== false,
    'nodes_manifest' => strpos($v2RouteContent, "NodeController@manifest") !== false,
    'orders' => strpos($v2RouteContent, "OrderController@index") !== false,
    'diagnostics' => strpos($v2RouteContent, "AppController@diagnosticsReport") !== false,
    'uses_app_user_middleware' => strpos($v2RouteContent, "'middleware' => 'app.user'") !== false || strpos($v2RouteContent, '"middleware" => "app.user"') !== false,
];

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
