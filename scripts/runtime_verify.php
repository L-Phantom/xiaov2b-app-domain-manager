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
    'config' => [
        'app_domain_enable' => (int) config('v2board.app_domain_enable', 0),
        'app_domain_public_host' => (string) config('v2board.app_domain_public_host', ''),
        'app_domain_subscribe_path' => (string) config('v2board.app_domain_subscribe_path', '/api/v1/client/custom_app/subscribe'),
        'app_domain_replace_host' => (string) config('v2board.app_domain_replace_host', ''),
        'app_api_domain_enable' => (int) config('v2board.app_api_domain_enable', 0),
        'app_api_domain_hosts' => array_values((array) config('v2board.app_api_domain_hosts', [])),
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
    $allServers = $serverService->getAvailableServers($user);
    $appServers = $serverService->getAvailableAppServers($user);

    $result['user'] = [
        'id' => $user->id,
        'email' => $user->email,
        'token' => $user->token,
    ];
    $result['servers'] = [
        'all_count' => count($allServers),
        'app_count' => count($appServers),
        'all_sample' => array_slice(array_map(function ($server) {
            return [
                'name' => $server['name'] ?? '',
                'host' => $server['host'] ?? '',
                'app_show' => $server['app_show'] ?? null,
                'type' => $server['type'] ?? ($server['protocol'] ?? ''),
            ];
        }, $allServers), 0, 5),
        'app_sample' => array_slice(array_map(function ($server) {
            return [
                'name' => $server['name'] ?? '',
                'host' => $server['host'] ?? '',
                'app_show' => $server['app_show'] ?? null,
                'type' => $server['type'] ?? ($server['protocol'] ?? ''),
            ];
        }, $appServers), 0, 5),
    ];
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
