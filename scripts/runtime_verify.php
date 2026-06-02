<?php

if ($argc < 2) {
    fwrite(STDERR, "Usage: php runtime_verify.php /path/to/site\n");
    exit(1);
}

$target = rtrim($argv[1], '/');
if (!is_dir($target)) {
    fwrite(STDERR, "Target directory not found: {$target}\n");
    exit(1);
}

require $target . '/vendor/autoload.php';
$app = require $target . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$result = [
    'services' => [
        'app_domain_service_exists' => class_exists(\App\Services\AppDomainService::class),
        'app_domain_rule_model_exists' => class_exists(\App\Models\AppDomainRule::class),
    ],
    'config' => [
        'app_domain_enable' => (int) config('v2board.app_domain_enable', 0),
        'app_domain_public_host' => (string) config('v2board.app_domain_public_host', ''),
        'app_domain_subscribe_path' => (string) config('v2board.app_domain_subscribe_path', '/api/v1/client/custom_app/subscribe'),
        'app_domain_replace_host' => (string) config('v2board.app_domain_replace_host', ''),
        'app_api_domain_enable' => (int) config('v2board.app_api_domain_enable', 0),
        'app_api_domain_hosts' => array_values((array) config('v2board.app_api_domain_hosts', [])),
    ],
    'rules' => [
        'app_domain_rule_enable' => (int) config('v2board.app_domain_rule_enable', 0),
        'table_exists' => \Illuminate\Support\Facades\Schema::hasTable('v2_app_domain_rules'),
        'count' => \Illuminate\Support\Facades\Schema::hasTable('v2_app_domain_rules') ? \App\Models\AppDomainRule::count() : null,
    ],
    'templates' => [
        'default_clash_meta_exists' => file_exists($target . '/resources/rules/default.clash.yaml'),
        'app_meta_exists' => file_exists($target . '/resources/rules/app.meta.clash.yaml'),
        'custom_app_meta_exists' => file_exists($target . '/resources/rules/custom.app.meta.clash.yaml'),
    ],
];

$user = \App\Models\User::where('banned', 0)->orderBy('id')->first();
if ($user) {
    $serverService = new \App\Services\ServerService();
    $appDomainService = new \App\Services\AppDomainService();
    $allServers = $serverService->getAvailableServers($user);
    $appServers = $serverService->getAvailableAppServers($user);
    $bootstrap = $appDomainService->buildBootstrapPayload($user);
    $versionBootstrap = $appDomainService->buildVersionBootstrap();

    $result['user'] = [
        'id' => $user->id,
        'email' => $user->email,
        'has_token' => !empty($user->token),
    ];
    $result['service_payload'] = [
        'admin_config_has_preview' => isset($appDomainService->getAdminConfig()['preview']),
        'bootstrap_has_subscribe_url' => isset($bootstrap['subscribe_url']),
        'bootstrap_api_url_count' => count($bootstrap['api_urls'] ?? []),
        'version_bootstrap_has_path' => ($versionBootstrap['bootstrap_path'] ?? '') === '/api/v1/client/app/bootstrap',
        'options_have_rule_table_state' => array_key_exists('rules_table_exists', $appDomainService->getOptions()),
    ];
    $result['servers'] = [
        'all_count' => count($allServers),
        'app_count' => count($appServers),
        'all_sample' => array_slice(array_map(function ($server) {
            return [
                'name' => $server['name'] ?? '',
                'host' => $server['host'] ?? '',
                'app_show' => $server['app_show'] ?? null,
                'app_domain_replace' => $server['app_domain_replace'] ?? null,
                'type' => $server['type'] ?? ($server['protocol'] ?? ''),
            ];
        }, $allServers), 0, 5),
        'app_sample' => array_slice(array_map(function ($server) {
            return [
                'name' => $server['name'] ?? '',
                'host' => $server['host'] ?? '',
                'app_show' => $server['app_show'] ?? null,
                'app_domain_replace' => $server['app_domain_replace'] ?? null,
                'type' => $server['type'] ?? ($server['protocol'] ?? ''),
            ];
        }, $appServers), 0, 5),
    ];
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
