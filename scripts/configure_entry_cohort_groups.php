<?php

if ($argc < 8) {
    fwrite(STDERR, "Usage: php configure_entry_cohort_groups.php /path/to/site --preview|--apply --domain-l=HOST --domain-a=HOST --domain-b=HOST --domain-c=HOST --domain-d=HOST [--source-host=HOST]\n");
    exit(1);
}

$target = rtrim($argv[1], '/');
$options = [];
foreach (array_slice($argv, 2) as $arg) {
    if (substr($arg, 0, 2) !== '--') {
        continue;
    }
    $parts = explode('=', substr($arg, 2), 2);
    $options[$parts[0]] = $parts[1] ?? true;
}
$modes = array_values(array_intersect(['preview', 'apply'], array_keys($options)));
if (count($modes) !== 1) {
    fwrite(STDERR, "Choose exactly one mode: --preview or --apply\n");
    exit(1);
}
$mode = $modes[0];
if (!is_dir($target) || !is_file($target . '/artisan')) {
    fwrite(STDERR, "Target site not found or artisan missing: {$target}\n");
    exit(1);
}

$normalizeHost = function ($host) {
    $host = strtolower(trim((string) $host));
    $host = preg_replace('#^https?://#', '', $host);
    $host = rtrim(explode('/', $host, 2)[0], '.');
    return $host;
};
$domainL = $normalizeHost($options['domain-l'] ?? '');
$domainA = $normalizeHost($options['domain-a'] ?? '');
$domainB = $normalizeHost($options['domain-b'] ?? '');
$domainC = $normalizeHost($options['domain-c'] ?? '');
$domainD = $normalizeHost($options['domain-d'] ?? '');
$domains = [$domainL, $domainA, $domainB, $domainC, $domainD];
foreach ($domains as $domain) {
    if ($domain === '' || strlen($domain) > 253 || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $domain)) {
        fwrite(STDERR, "Invalid cohort domain\n");
        exit(1);
    }
}
if (count(array_unique($domains)) !== 5) {
    fwrite(STDERR, "Cohort domains must all be different\n");
    exit(1);
}

require $target . '/vendor/autoload.php';
$app = require $target . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$db = Illuminate\Support\Facades\DB::connection();
$schema = Illuminate\Support\Facades\Schema::getFacadeRoot();
if (!$schema->hasTable('v2_app_domain_groups') || !$schema->hasTable('v2_app_domain_bindings') || !$schema->hasColumn('v2_app_domain_groups', 'assignment_only')) {
    fwrite(STDERR, "Cohort group schema missing; run migrate_app_domain.php --apply first\n");
    exit(1);
}

$serverTables = [
    'shadowsocks' => 'v2_server_shadowsocks',
    'vmess' => 'v2_server_vmess',
    'trojan' => 'v2_server_trojan',
    'vless' => 'v2_server_vless',
    'hysteria' => 'v2_server_hysteria',
    'tuic' => 'v2_server_tuic',
    'anytls' => 'v2_server_anytls',
    'v2node' => 'v2_server_v2node',
];

$hostCounts = [];
$allNodes = [];
foreach ($serverTables as $type => $table) {
    if (!$schema->hasTable($table) || !$schema->hasColumn($table, 'host')) {
        continue;
    }
    foreach ($db->table($table)->get(['id', 'host']) as $row) {
        $host = $normalizeHost($row->host);
        if ($host === '') {
            continue;
        }
        $hostCounts[$host] = ($hostCounts[$host] ?? 0) + 1;
        $allNodes[] = ['server_type' => $type, 'server_id' => (int) $row->id, 'host' => $host];
    }
}
arsort($hostCounts);
$sourceHost = $normalizeHost($options['source-host'] ?? '');
if ($sourceHost === '') {
    $sourceHost = (string) (array_key_first($hostCounts) ?? '');
}
$nodes = array_values(array_filter($allNodes, function ($node) use ($sourceHost) {
    return $node['host'] === $sourceHost;
}));
if ($sourceHost === '' || !$nodes) {
    fwrite(STDERR, "No nodes found for source host\n");
    exit(1);
}

$names = [
    'baseline' => 'Entry Cohort Baseline',
    'test_a' => 'Entry Cohort Test A',
    'test_b' => 'Entry Cohort Test B',
    'test_c' => 'Entry Cohort Test C',
    'test_d' => 'Entry Cohort Test D',
];
$domains = [
    'baseline' => $domainL,
    'test_a' => $domainA,
    'test_b' => $domainB,
    'test_c' => $domainC,
    'test_d' => $domainD,
];
$existing = $db->table('v2_app_domain_groups')->whereIn('name', array_values($names))->get()->keyBy('name');
$result = [
    'mode' => $mode,
    'database' => $db->getDatabaseName(),
    'source_host' => $sourceHost,
    'source_node_count' => count($nodes),
    'groups' => [],
];
foreach ($names as $cohort => $name) {
    $row = $existing->get($name);
    $result['groups'][$cohort] = [
        'id' => $row ? (int) $row->id : null,
        'name' => $name,
        'domain' => $domains[$cohort],
        'binding_count' => $row ? (int) $db->table('v2_app_domain_bindings')->where('group_id', $row->id)->where('enable', 1)->count() : 0,
    ];
}

if ($mode === 'preview') {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

$now = time();
$db->transaction(function () use ($db, $names, $domains, $nodes, $now, &$result) {
    foreach ($names as $cohort => $name) {
        $existing = $db->table('v2_app_domain_groups')->where('name', $name)->first();
        $values = [
            'enable' => 1,
            'sort' => -100,
            'domain' => $domains[$cohort],
            'user_group_ids' => '[]',
            'plan_ids' => '[]',
            'hide_matched_nodes' => 0,
            'assignment_only' => 1,
            'remark' => 'Explicit cohort assignment only',
            'updated_at' => $now,
        ];
        if ($existing) {
            $groupId = (int) $existing->id;
            $db->table('v2_app_domain_groups')->where('id', $groupId)->update($values);
        } else {
            $values['name'] = $name;
            $values['created_at'] = $now;
            $groupId = (int) $db->table('v2_app_domain_groups')->insertGetId($values);
        }

        $wanted = [];
        foreach ($nodes as $node) {
            $key = $node['server_type'] . ':' . $node['server_id'];
            $wanted[$key] = true;
            $db->table('v2_app_domain_bindings')->updateOrInsert(
                ['group_id' => $groupId, 'server_type' => $node['server_type'], 'server_id' => $node['server_id']],
                ['enable' => 1, 'sort' => 0, 'port' => null, 'remark' => 'Cohort route', 'updated_at' => $now, 'created_at' => $now]
            );
        }
        foreach ($db->table('v2_app_domain_bindings')->where('group_id', $groupId)->get(['id', 'server_type', 'server_id']) as $binding) {
            $key = $binding->server_type . ':' . (int) $binding->server_id;
            if (!isset($wanted[$key])) {
                $db->table('v2_app_domain_bindings')->where('id', $binding->id)->update(['enable' => 0, 'updated_at' => $now]);
            }
        }

        $result['groups'][$cohort]['id'] = $groupId;
        $result['groups'][$cohort]['binding_count'] = count($nodes);
    }
});

$result['mode'] = 'apply';
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
