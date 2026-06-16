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
        'app_domain_group_model_exists' => class_exists(\App\Models\AppDomainGroup::class),
        'app_domain_binding_model_exists' => class_exists(\App\Models\AppDomainBinding::class),
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
        'has_port_column' => \Illuminate\Support\Facades\Schema::hasTable('v2_app_domain_rules') && \Illuminate\Support\Facades\Schema::hasColumn('v2_app_domain_rules', 'port'),
        'count' => \Illuminate\Support\Facades\Schema::hasTable('v2_app_domain_rules') ? \App\Models\AppDomainRule::count() : null,
        'groups_table_exists' => \Illuminate\Support\Facades\Schema::hasTable('v2_app_domain_groups'),
        'bindings_table_exists' => \Illuminate\Support\Facades\Schema::hasTable('v2_app_domain_bindings'),
        'groups_has_risk_levels' => \Illuminate\Support\Facades\Schema::hasTable('v2_app_domain_groups') && \Illuminate\Support\Facades\Schema::hasColumn('v2_app_domain_groups', 'risk_levels'),
        'groups_has_disposition_statuses' => \Illuminate\Support\Facades\Schema::hasTable('v2_app_domain_groups') && \Illuminate\Support\Facades\Schema::hasColumn('v2_app_domain_groups', 'disposition_statuses'),
        'groups_has_hide_matched_nodes' => \Illuminate\Support\Facades\Schema::hasTable('v2_app_domain_groups') && \Illuminate\Support\Facades\Schema::hasColumn('v2_app_domain_groups', 'hide_matched_nodes'),
        'subscribe_ip_cache_has_asn' => \Illuminate\Support\Facades\Schema::hasTable('v2_subscribe_ip_cache') && \Illuminate\Support\Facades\Schema::hasColumn('v2_subscribe_ip_cache', 'asn'),
        'subscribe_ip_cache_has_network_type' => \Illuminate\Support\Facades\Schema::hasTable('v2_subscribe_ip_cache') && \Illuminate\Support\Facades\Schema::hasColumn('v2_subscribe_ip_cache', 'network_type'),
        'subscribe_ip_cache_has_ip_risk_type' => \Illuminate\Support\Facades\Schema::hasTable('v2_subscribe_ip_cache') && \Illuminate\Support\Facades\Schema::hasColumn('v2_subscribe_ip_cache', 'ip_risk_type'),
        'subscribe_dispositions_table_exists' => \Illuminate\Support\Facades\Schema::hasTable('v2_subscribe_dispositions'),
        'subscribe_disposition_logs_table_exists' => \Illuminate\Support\Facades\Schema::hasTable('v2_subscribe_disposition_logs'),
        'subscribe_risk_snapshots_table_exists' => \Illuminate\Support\Facades\Schema::hasTable('v2_subscribe_risk_snapshots'),
        'subscribe_disposition_count' => \Illuminate\Support\Facades\Schema::hasTable('v2_subscribe_dispositions') ? \Illuminate\Support\Facades\DB::table('v2_subscribe_dispositions')->count() : null,
        'subscribe_risk_snapshot_count' => \Illuminate\Support\Facades\Schema::hasTable('v2_subscribe_risk_snapshots') ? \Illuminate\Support\Facades\DB::table('v2_subscribe_risk_snapshots')->count() : null,
        'subscribe_ip_cache_count' => \Illuminate\Support\Facades\Schema::hasTable('v2_subscribe_ip_cache') ? \Illuminate\Support\Facades\DB::table('v2_subscribe_ip_cache')->count() : null,
        'subscribe_ip_intelligence_count' => \Illuminate\Support\Facades\Schema::hasTable('v2_subscribe_ip_cache') ? \Illuminate\Support\Facades\DB::table('v2_subscribe_ip_cache')->where(function ($query) {
            $query->whereNotNull('asn')
                ->orWhereNotNull('as_name')
                ->orWhereNotNull('ip_risk_type');
        })->count() : null,
        'group_count' => \Illuminate\Support\Facades\Schema::hasTable('v2_app_domain_groups') ? \App\Models\AppDomainGroup::count() : null,
        'binding_count' => \Illuminate\Support\Facades\Schema::hasTable('v2_app_domain_bindings') ? \App\Models\AppDomainBinding::count() : null,
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
    $options = $appDomainService->getOptions();

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
        'options_have_rule_table_state' => array_key_exists('rules_table_exists', $options),
        'options_node_count' => count($options['nodes'] ?? []),
    ];
    $result['servers'] = [
        'all_count' => count($allServers),
        'app_count' => count($appServers),
        'all_sample' => array_slice(array_map(function ($server) {
            return [
                'name' => $server['name'] ?? '',
                'host' => $server['host'] ?? '',
                'port' => $server['port'] ?? null,
                'app_show' => $server['app_show'] ?? null,
                'app_domain_replace' => $server['app_domain_replace'] ?? null,
                'type' => $server['type'] ?? ($server['protocol'] ?? ''),
            ];
        }, $allServers), 0, 5),
        'app_sample' => array_slice(array_map(function ($server) {
            return [
                'name' => $server['name'] ?? '',
                'host' => $server['host'] ?? '',
                'port' => $server['port'] ?? null,
                'app_show' => $server['app_show'] ?? null,
                'app_domain_replace' => $server['app_domain_replace'] ?? null,
                'type' => $server['type'] ?? ($server['protocol'] ?? ''),
            ];
        }, $appServers), 0, 5),
    ];
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
