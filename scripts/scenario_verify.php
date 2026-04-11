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
foreach ([
    \App\Models\ServerShadowsocks::class,
    \App\Models\ServerVmess::class,
    \App\Models\ServerTrojan::class,
    \App\Models\ServerVless::class,
    \App\Models\ServerV2node::class,
] as $modelClass) {
    if (!class_exists($modelClass)) {
        continue;
    }
    $server = $modelClass::query()->orderBy('id')->first();
    if ($server) {
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
    $server->save();

    config([
        'v2board.app_domain_enable' => 1,
        'v2board.app_domain_replace_host' => $replaceHost,
    ]);

    $service = new \App\Services\ServerService();
    $allServers = $service->getAvailableServers($user);
    $appServers = $service->getAvailableAppServers($user);

    $result = [
        'selected_server' => [
            'model' => get_class($server),
            'id' => $server->id,
            'name' => $server->name ?? '',
            'original_host' => $server->host ?? '',
        ],
        'expectation' => [
            'all_servers_should_keep_original_host' => true,
            'app_servers_should_use_replace_host' => $replaceHost,
        ],
        'all_sample' => array_slice(array_map(function ($item) {
            return [
                'name' => $item['name'] ?? '',
                'host' => $item['host'] ?? '',
                'app_show' => $item['app_show'] ?? null,
                'type' => $item['type'] ?? ($item['protocol'] ?? ''),
            ];
        }, $allServers), 0, 5),
        'app_sample' => array_slice(array_map(function ($item) {
            return [
                'name' => $item['name'] ?? '',
                'host' => $item['host'] ?? '',
                'app_show' => $item['app_show'] ?? null,
                'type' => $item['type'] ?? ($item['protocol'] ?? ''),
            ];
        }, $appServers), 0, 5),
    ];

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} finally {
    \Illuminate\Support\Facades\DB::rollBack();
}
