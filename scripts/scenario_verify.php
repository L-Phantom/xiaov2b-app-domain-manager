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

function clearAppDomainRiskSnapshotCache(): void
{
    try {
        $property = new ReflectionProperty(\App\Services\AppDomainService::class, 'riskSnapshots');
        $property->setAccessible(true);
        $property->setValue(null, []);
    } catch (\Throwable $e) {
    }
}

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
    \App\Models\ServerHysteria::class => 'hysteria',
    \App\Models\ServerTuic::class => 'tuic',
    \App\Models\ServerAnytls::class => 'anytls',
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

$service = new \App\Services\ServerService();
$appDomainService = new \App\Services\AppDomainService();

    config([
        'v2board.app_domain_enable' => 1,
        'v2board.app_domain_replace_host' => $replaceHost,
        'v2board.app_domain_rule_enable' => 0,
    ]);

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
    $blacklistHost = 'blacklist-' . $replaceHost;
    $blacklistPort = 26443;
    $ruleMatchedServer = null;
    $bindingPlainMatchedServer = null;
    $bindingMatchedServer = null;
    $blacklistPlainMatchedServer = null;
    $blacklistMatchedServer = null;
    $blacklistPlainClearedServer = null;
    $blacklistClearedServer = null;
    $blacklistPreview = null;
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
        if (\Illuminate\Support\Facades\Schema::hasTable('v2_subscribe_dispositions') && class_exists(\App\Models\SubscribeDisposition::class)) {
            \App\Models\SubscribeDisposition::where('user_id', (int) $user->id)->delete();
            clearAppDomainRiskSnapshotCache();
        }
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
        $bindingPlainServers = $service->getAvailableServers($user);
        foreach ($bindingPlainServers as $item) {
            if ((int) ($item['id'] ?? 0) === (int) $server->id && ($item['type'] ?? '') === $serverType) {
                $bindingPlainMatchedServer = $item;
                break;
            }
        }
        $bindingServers = $service->getAvailableAppServers($user);
        foreach ($bindingServers as $item) {
            if ((int) ($item['id'] ?? 0) === (int) $server->id && ($item['type'] ?? '') === $serverType) {
                $bindingMatchedServer = $item;
                break;
            }
        }

        if (\Illuminate\Support\Facades\Schema::hasTable('v2_subscribe_dispositions') && class_exists(\App\Models\SubscribeDisposition::class)) {
            \App\Models\AppDomainGroup::where('name', 'scenario binding group')->update(['sort' => 50]);
            $appDomainService->saveGroup([
                'name' => 'scenario blacklist entrance group',
                'enable' => 1,
                'sort' => 1,
                'domain' => $blacklistHost,
                'user_group_ids' => [],
                'plan_ids' => [],
                'risk_levels' => [],
                'disposition_statuses' => ['blacklist_suggested'],
                'hide_matched_nodes' => 0,
                'remark' => 'scenario blacklist entrance group',
            ]);
            $blacklistGroup = \App\Models\AppDomainGroup::where('name', 'scenario blacklist entrance group')->first();
            $appDomainService->saveBinding([
                'group_id' => (int) $blacklistGroup->id,
                'enable' => 1,
                'sort' => 1,
                'server_type' => $serverType,
                'server_id' => (int) $server->id,
                'port' => $blacklistPort,
                'remark' => 'scenario blacklist binding',
            ]);
            \App\Models\SubscribeDisposition::updateOrCreate(
                ['user_id' => (int) $user->id],
                [
                    'email' => $user->email ?? '',
                    'status' => 'blacklist_suggested',
                    'level' => '极危险',
                    'note' => 'scenario verify',
                    'created_at' => time(),
                    'updated_at' => time(),
                ]
            );
            clearAppDomainRiskSnapshotCache();
            $blacklistPlainServers = $service->getAvailableServers($user);
            foreach ($blacklistPlainServers as $item) {
                if ((int) ($item['id'] ?? 0) === (int) $server->id && ($item['type'] ?? '') === $serverType) {
                    $blacklistPlainMatchedServer = $item;
                    break;
                }
            }
            $blacklistServers = $service->getAvailableAppServers($user);
            foreach ($blacklistServers as $item) {
                if ((int) ($item['id'] ?? 0) === (int) $server->id && ($item['type'] ?? '') === $serverType) {
                    $blacklistMatchedServer = $item;
                    break;
                }
            }
            $blacklistPreview = $appDomainService->previewDispatchForUserId((int) $user->id, 20);
            \App\Models\SubscribeDisposition::where('user_id', (int) $user->id)->delete();
            clearAppDomainRiskSnapshotCache();
            $blacklistPlainClearedServers = $service->getAvailableServers($user);
            foreach ($blacklistPlainClearedServers as $item) {
                if ((int) ($item['id'] ?? 0) === (int) $server->id && ($item['type'] ?? '') === $serverType) {
                    $blacklistPlainClearedServer = $item;
                    break;
                }
            }
            $blacklistClearedServers = $service->getAvailableAppServers($user);
            foreach ($blacklistClearedServers as $item) {
                if ((int) ($item['id'] ?? 0) === (int) $server->id && ($item['type'] ?? '') === $serverType) {
                    $blacklistClearedServer = $item;
                    break;
                }
            }
        }
    }

    $checks = [
        'all_servers_keep_original_host' => ($allMatchedServer['host'] ?? null) === ($server->host ?? null),
        'app_replace_enabled_uses_replace_host' => ($appMatchedServer['host'] ?? null) === $replaceHost,
        'app_replace_disabled_keeps_original_host' => ($appMatchedServerWithoutReplace['host'] ?? null) === ($server->host ?? null),
        'rule_match_uses_rule_host' => $ruleTableExists ? (($ruleMatchedServer['host'] ?? null) === $ruleHost) : true,
        'rule_match_uses_rule_port' => $ruleTableExists ? ((int) ($ruleMatchedServer['port'] ?? 0) === $rulePort) : true,
        'ordinary_binding_does_not_affect_plain_subscribe' => $bindingTableExists ? (($bindingPlainMatchedServer['host'] ?? null) !== $bindingHost) : true,
        'ordinary_binding_port_does_not_affect_plain_subscribe' => $bindingTableExists ? ((int) ($bindingPlainMatchedServer['port'] ?? 0) !== $bindingPort) : true,
        'binding_match_overrides_rule_host' => $bindingTableExists ? (($bindingMatchedServer['host'] ?? null) === $bindingHost) : true,
        'binding_match_overrides_rule_port' => $bindingTableExists ? ((int) ($bindingMatchedServer['port'] ?? 0) === $bindingPort) : true,
        'blacklist_plain_subscribe_uses_dedicated_host' => $bindingTableExists && \Illuminate\Support\Facades\Schema::hasTable('v2_subscribe_dispositions') ? (($blacklistPlainMatchedServer['host'] ?? null) === $blacklistHost) : true,
        'blacklist_plain_subscribe_uses_dedicated_port' => $bindingTableExists && \Illuminate\Support\Facades\Schema::hasTable('v2_subscribe_dispositions') ? ((int) ($blacklistPlainMatchedServer['port'] ?? 0) === $blacklistPort) : true,
        'blacklist_plain_clear_stops_dedicated_host' => $bindingTableExists && \Illuminate\Support\Facades\Schema::hasTable('v2_subscribe_dispositions') ? (($blacklistPlainClearedServer['host'] ?? null) !== $blacklistHost) : true,
        'blacklist_disposition_uses_dedicated_host' => $bindingTableExists && \Illuminate\Support\Facades\Schema::hasTable('v2_subscribe_dispositions') ? (($blacklistMatchedServer['host'] ?? null) === $blacklistHost) : true,
        'blacklist_disposition_uses_dedicated_port' => $bindingTableExists && \Illuminate\Support\Facades\Schema::hasTable('v2_subscribe_dispositions') ? ((int) ($blacklistMatchedServer['port'] ?? 0) === $blacklistPort) : true,
        'blacklist_clear_stops_dedicated_host' => $bindingTableExists && \Illuminate\Support\Facades\Schema::hasTable('v2_subscribe_dispositions') ? (($blacklistClearedServer['host'] ?? null) !== $blacklistHost) : true,
        'blacklist_preview_reports_group' => $bindingTableExists && \Illuminate\Support\Facades\Schema::hasTable('v2_subscribe_dispositions') ? (bool) array_filter($blacklistPreview['nodes'] ?? [], function ($node) {
            return ($node['group_name'] ?? '') === 'scenario blacklist entrance group';
        }) : true,
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
            'blacklist_disposition_should_use_dedicated_host' => $bindingTableExists ? $blacklistHost : 'skipped',
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
