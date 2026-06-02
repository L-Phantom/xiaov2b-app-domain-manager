<?php

if ($argc < 2) {
    fwrite(STDERR, "Usage: php scenario_verify.php /path/to/site [replace-host]\n");
    exit(1);
}

$target = rtrim($argv[1], '/');
$replaceHost = $argv[2] ?? 'app-edge.example.com';
if (!is_dir($target)) {
    fwrite(STDERR, "Target directory not found: {$target}\n");
    exit(1);
}

require $target . '/vendor/autoload.php';
$app = require $target . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = \App\Models\User::where('banned', 0)->orderBy('id')->first();
if (!$user) {
    fwrite(STDERR, "No active user found\n");
    exit(1);
}

$server = null;
$serverType = null;
foreach ([
    \App\Models\ServerShadowsocks::class => 'shadowsocks',
    \App\Models\ServerVmess::class => 'vmess',
    \App\Models\ServerTrojan::class => 'trojan',
    \App\Models\ServerVless::class => 'vless',
    \App\Models\ServerV2node::class => 'v2node',
] as $modelClass => $type) {
    if (!class_exists($modelClass)) {
        continue;
    }
    $server = $modelClass::query()->orderBy('id')->first();
    if ($server) {
        $serverType = $type;
        break;
    }
}

if (!$server) {
    fwrite(STDERR, "No server found for scenario verify\n");
    exit(1);
}

\Illuminate\Support\Facades\DB::beginTransaction();

try {
    $server->app_show = 1;
    $server->app_domain_replace = 1;
    $server->save();

    config([
        'v2board.app_domain_enable' => 1,
        'v2board.app_domain_replace_host' => $replaceHost,
    ]);

    $service = new \App\Services\ServerService();
    $allServers = $service->getAvailableServers($user);
    $appServers = $service->getAvailableAppServers($user);
    $allMatchedServer = null;
    $appMatchedServer = null;
    foreach ($allServers as $item) {
        if ((int) ($item['id'] ?? 0) === (int) $server->id && ($item['type'] ?? '') === $serverType) {
            $allMatchedServer = $item;
            break;
        }
    }
    foreach ($appServers as $item) {
        if ((int) ($item['id'] ?? 0) === (int) $server->id && ($item['type'] ?? '') === $serverType) {
            $appMatchedServer = $item;
            break;
        }
    }

    $server->app_domain_replace = 0;
    $server->save();
    $appServersWithoutReplace = $service->getAvailableAppServers($user);
    $appMatchedServerWithoutReplace = null;
    foreach ($appServersWithoutReplace as $item) {
        if ((int) ($item['id'] ?? 0) === (int) $server->id && ($item['type'] ?? '') === $serverType) {
            $appMatchedServerWithoutReplace = $item;
            break;
        }
    }

    $checks = [
        'all_servers_keep_original_host' => ($allMatchedServer['host'] ?? null) === ($server->host ?? null),
        'app_replace_enabled_uses_replace_host' => ($appMatchedServer['host'] ?? null) === $replaceHost,
        'app_replace_disabled_keeps_original_host' => ($appMatchedServerWithoutReplace['host'] ?? null) === ($server->host ?? null),
    ];

    $result = [
        'selected_server' => [
            'model' => get_class($server),
            'id' => $server->id,
            'name' => $server->name ?? '',
            'type' => $serverType,
            'original_host' => $server->host ?? '',
        ],
        'expectation' => [
            'all_servers_should_keep_original_host' => true,
            'app_servers_should_use_replace_host_when_node_replace_enabled' => $replaceHost,
            'app_servers_should_keep_original_host_when_node_replace_disabled' => true,
        ],
        'checks' => $checks,
        'all_sample' => array_slice(array_map(function ($item) {
            return [
                'name' => $item['name'] ?? '',
                'host' => $item['host'] ?? '',
                'app_show' => $item['app_show'] ?? null,
                'app_domain_replace' => $item['app_domain_replace'] ?? null,
                'type' => $item['type'] ?? ($item['protocol'] ?? ''),
            ];
        }, $allServers), 0, 5),
        'app_sample' => array_slice(array_map(function ($item) {
            return [
                'name' => $item['name'] ?? '',
                'host' => $item['host'] ?? '',
                'app_show' => $item['app_show'] ?? null,
                'app_domain_replace' => $item['app_domain_replace'] ?? null,
                'type' => $item['type'] ?? ($item['protocol'] ?? ''),
            ];
        }, $appServers), 0, 5),
    ];

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    if (in_array(false, $checks, true)) {
        exit(2);
    }
} finally {
    \Illuminate\Support\Facades\DB::rollBack();
}
