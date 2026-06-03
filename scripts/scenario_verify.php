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
        'v2board.app_domain_rule_enable' => 0,
    ]);

$service = new \App\Services\ServerService();
$appDomainService = new \App\Services\AppDomainService();
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

    $ruleHost = 'rule-' . $replaceHost;
    $rulePort = 24443;
    $bindingHost = 'binding-' . $replaceHost;
    $bindingPort = 25443;
    $ruleMatchedServer = null;
    $bindingMatchedServer = null;
    $ruleUnmatchedServer = null;
    $ruleTableExists = \Illuminate\Support\Facades\Schema::hasTable('v2_app_domain_rules');
    $bindingTableExists = \Illuminate\Support\Facades\Schema::hasTable('v2_app_domain_groups') && \Illuminate\Support\Facades\Schema::hasTable('v2_app_domain_bindings');
    if ($ruleTableExists && class_exists(\App\Models\AppDomainRule::class)) {
        $appDomainService->saveRule([
            'name' => 'scenario verify',
            'enable' => 1,
            'sort' => 1,
            'domain' => $ruleHost,
            'port' => $rulePort,
            'user_group_ids' => [],
            'plan_ids' => [],
            'server_types' => [$serverType],
            'server_ids' => [(int) $server->id],
            'protocols' => [],
            'replace_node_host' => 1,
            'replace_subscribe_host' => 0,
            'remark' => 'scenario verify',
        ]);
        $appDomainService->saveRule([
            'name' => 'scenario subscribe verify',
            'enable' => 1,
            'sort' => 2,
            'domain' => 'subscribe-' . $replaceHost,
            'user_group_ids' => [(int) $user->group_id],
            'plan_ids' => [],
            'server_types' => [],
            'server_ids' => [],
            'protocols' => [],
            'replace_node_host' => 0,
            'replace_subscribe_host' => 1,
            'remark' => 'scenario subscribe verify',
        ]);
        $savedRules = \App\Models\AppDomainRule::whereIn('name', ['scenario verify', 'scenario subscribe verify'])
            ->orderBy('sort', 'ASC')
            ->get();
        $appDomainService->sortRules($savedRules->pluck('id')->reverse()->values()->toArray());
        $server->app_domain_replace = 1;
        $server->save();
        config([
            'v2board.app_domain_enable' => 1,
            'v2board.app_domain_rule_enable' => 1,
        ]);
        $ruleServers = $service->getAvailableAppServers($user);
        foreach ($ruleServers as $item) {
            if ((int) ($item['id'] ?? 0) === (int) $server->id && ($item['type'] ?? '') === $serverType) {
                $ruleMatchedServer = $item;
                break;
            }
        }
        $ruleUnmatchedServer = $appDomainService->applyToServer($user, [
            'id' => 99999999,
            'type' => $serverType,
            'name' => 'scenario unmatched',
            'host' => 'scenario-origin.example.com',
            'app_domain_replace' => 1,
        ]);
        $subscribeUrl = $appDomainService->buildSubscribeUrl($user->token);
        $crudRule = \App\Models\AppDomainRule::where('name', 'scenario subscribe verify')->first();
        if ($crudRule) {
            $appDomainService->dropRule((int) $crudRule->id);
        }
    }
    if ($bindingTableExists && class_exists(\App\Models\AppDomainGroup::class) && class_exists(\App\Models\AppDomainBinding::class)) {
        $appDomainService->saveGroup([
            'name' => 'scenario binding group',
            'enable' => 1,
            'sort' => 1,
            'domain' => $bindingHost,
            'user_group_ids' => [],
            'plan_ids' => [],
            'remark' => 'scenario binding group',
        ]);
        $group = \App\Models\AppDomainGroup::where('name', 'scenario binding group')->first();
        $appDomainService->saveBinding([
            'group_id' => (int) $group->id,
            'enable' => 1,
            'sort' => 1,
            'server_type' => $serverType,
            'server_id' => (int) $server->id,
            'port' => $bindingPort,
            'remark' => 'scenario binding',
        ]);
        $bindingServers = $service->getAvailableAppServers($user);
        foreach ($bindingServers as $item) {
            if ((int) ($item['id'] ?? 0) === (int) $server->id && ($item['type'] ?? '') === $serverType) {
                $bindingMatchedServer = $item;
                break;
            }
        }
    }

    $checks = [
        'all_servers_keep_original_host' => ($allMatchedServer['host'] ?? null) === ($server->host ?? null),
        'app_replace_enabled_uses_replace_host' => ($appMatchedServer['host'] ?? null) === $replaceHost,
        'app_replace_disabled_keeps_original_host' => ($appMatchedServerWithoutReplace['host'] ?? null) === ($server->host ?? null),
        'rule_match_uses_rule_host' => $ruleTableExists ? (($ruleMatchedServer['host'] ?? null) === $ruleHost) : true,
        'rule_match_uses_rule_port' => $ruleTableExists ? ((int) ($ruleMatchedServer['port'] ?? 0) === $rulePort) : true,
        'binding_match_overrides_rule_host' => $bindingTableExists ? (($bindingMatchedServer['host'] ?? null) === $bindingHost) : true,
        'binding_match_overrides_rule_port' => $bindingTableExists ? ((int) ($bindingMatchedServer['port'] ?? 0) === $bindingPort) : true,
        'rule_unmatched_keeps_original_host' => $ruleTableExists ? (($ruleUnmatchedServer['host'] ?? null) === 'scenario-origin.example.com') : true,
        'subscribe_rule_uses_user_matched_host' => $ruleTableExists ? (strpos($subscribeUrl ?? '', 'https://subscribe-' . $replaceHost) === 0) : true,
        'rule_crud_sort_drop_ok' => $ruleTableExists ? (\App\Models\AppDomainRule::where('name', 'scenario subscribe verify')->count() === 0) : true,
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
            'rule_match_should_use_rule_host_when_table_exists' => $ruleTableExists ? $ruleHost : 'skipped',
        ],
        'rule_table_exists' => $ruleTableExists,
        'binding_table_exists' => $bindingTableExists,
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
