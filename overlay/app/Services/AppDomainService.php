<?php

namespace App\Services;

use App\Models\AppDomainRule;
use App\Models\AppDomainBinding;
use App\Models\AppDomainGroup;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\SubscribeMonitorService;
use App\Utils\Helper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class AppDomainService
{
    protected static $riskSnapshots = [];

    protected const SUPPORTED_SERVER_TYPES = [
        'shadowsocks',
        'vmess',
        'trojan',
        'vless',
        'hysteria',
        'tuic',
        'anytls',
        'v2node',
    ];

    protected const SERVER_OPTION_TABLES = [
        'shadowsocks' => 'v2_server_shadowsocks',
        'vmess' => 'v2_server_vmess',
        'trojan' => 'v2_server_trojan',
        'vless' => 'v2_server_vless',
        'hysteria' => 'v2_server_hysteria',
        'tuic' => 'v2_server_tuic',
        'anytls' => 'v2_server_anytls',
        'v2node' => 'v2_server_v2node',
    ];

    public function getConfig(): array
    {
        return [
            'app_domain_enable' => (int) config('v2board.app_domain_enable', 0),
            'app_domain_public_host' => $this->normalizeEndpoint(config('v2board.app_domain_public_host', '')),
            'app_domain_subscribe_path' => $this->normalizePath(config('v2board.app_domain_subscribe_path', '/api/v1/client/custom_app/subscribe')),
            'app_domain_replace_host' => $this->normalizeHost(config('v2board.app_domain_replace_host', '')),
            'app_api_domain_enable' => (int) config('v2board.app_api_domain_enable', 0),
            'app_api_domain_hosts' => $this->normalizeEndpoints((array) config('v2board.app_api_domain_hosts', [])),
            'app_api_domain_encrypt_enable' => (int) config('v2board.app_api_domain_encrypt_enable', 0),
            'app_api_domain_encrypt_key' => trim((string) config('v2board.app_api_domain_encrypt_key', '')),
            'app_domain_rule_enable' => (int) config('v2board.app_domain_rule_enable', 0),
        ];
    }

    public function getAdminConfig(): array
    {
        $config = $this->getConfig();
        $token = 'YOUR_TOKEN';
        $subscribePath = $config['app_domain_subscribe_path'];
        $publicHost = $config['app_domain_public_host'];

        $config['preview'] = [
            'subscribe_example' => $publicHost
                ? sprintf('%s%s?token=%s', $publicHost, $subscribePath, $token)
                : sprintf('%s?token=%s', $subscribePath, $token),
            'bootstrap_path' => '/api/v1/client/app/bootstrap',
            'app_config_path' => '/api/v1/client/app/getConfig',
            'app_version_path' => '/api/v1/client/app/getVersion',
            'api_urls' => array_map(function ($host) {
                return sprintf('%s/api/v1/client/app/bootstrap', $host);
            }, $config['app_api_domain_hosts'])
        ];

        return $config;
    }

    public function saveConfig(array $data): bool
    {
        $config = config('v2board', []);
        $publicHost = $this->normalizeEndpoint($data['app_domain_public_host'] ?? '');
        $replaceHost = $this->normalizeHost($data['app_domain_replace_host'] ?? '');
        $apiHosts = $this->normalizeEndpoints((array) ($data['app_api_domain_hosts'] ?? []));

        $config['app_domain_enable'] = (int) $data['app_domain_enable'];
        $config['app_domain_public_host'] = $publicHost;
        $config['app_domain_subscribe_path'] = $this->normalizePath($data['app_domain_subscribe_path'] ?? '');
        $config['app_domain_replace_host'] = $replaceHost;
        $config['app_api_domain_enable'] = (int) $data['app_api_domain_enable'];
        $config['app_api_domain_hosts'] = $apiHosts;
        $config['app_api_domain_encrypt_enable'] = (int) $data['app_api_domain_encrypt_enable'];
        $config['app_api_domain_encrypt_key'] = trim((string) ($data['app_api_domain_encrypt_key'] ?? ''));
        $config['app_domain_rule_enable'] = (int) ($data['app_domain_rule_enable'] ?? config('v2board.app_domain_rule_enable', 0));

        $config['app_domain_global_rule_enable'] = (int) $data['app_domain_enable'];
        $config['app_domain_global_replace_host'] = $replaceHost;
        $config['app_domain_replace_map'] = $replaceHost ? [[
            'enabled' => 1,
            'match_host' => '*',
            'replace_host' => $replaceHost,
        ]] : [];

        $configPath = base_path('/config/v2board.php');
        $contents = var_export($config, true);
        if (!File::put($configPath, "<?php\n\nreturn {$contents};\n")) {
            return false;
        }

        config(['v2board' => $config]);
        $this->clearConfigCache($configPath);

        return true;
    }

    public function buildSubscribeUrl(string $token): string
    {
        $config = $this->getConfig();
        $path = "{$config['app_domain_subscribe_path']}?token={$token}";
        $user = $this->findUserByToken($token);
        $rule = $this->matchSubscribeRule($user);
        $host = $rule && (int) $rule->replace_subscribe_host === 1
            ? $this->normalizeEndpoint($rule->domain)
            : $config['app_domain_public_host'];

        if ($host !== '') {
            return $host . $path;
        }

        return url($path);
    }

    public function buildBootstrapPayload($user): array
    {
        $token = is_array($user) ? ($user['token'] ?? '') : ($user->token ?? '');
        $apiHosts = $this->getApiDomainHosts();
        $apiUrls = $this->buildApiUrls($apiHosts);

        $payload = [
            'subscribe_url' => $this->buildSubscribeUrl($token),
            'subscribe_path' => $this->normalizePath(config('v2board.app_domain_subscribe_path', '/api/v1/client/custom_app/subscribe')),
            'replace_host' => $this->resolveGlobalReplaceHost(),
            'api_domain_enable' => (int) config('v2board.app_api_domain_enable', 0),
            'api_domains' => $apiHosts,
            'api_urls' => $apiUrls,
            'app_config_url' => '/api/v1/client/app/getConfig',
            'app_version_url' => '/api/v1/client/app/getVersion',
        ];

        $encrypted = $this->buildEncryptedApiUrls($apiUrls);
        if ($encrypted) {
            $payload['encrypted_api_urls'] = $encrypted;
        }

        return $payload;
    }

    public function buildVersionBootstrap(): array
    {
        $apiHosts = $this->getApiDomainHosts();
        $bootstrap = [
            'api_domain_enable' => (int) config('v2board.app_api_domain_enable', 0),
            'api_domains' => $apiHosts,
            'bootstrap_path' => '/api/v1/client/app/bootstrap',
        ];

        $encrypted = $this->buildEncryptedApiUrls($this->buildApiUrls($apiHosts));
        if ($encrypted) {
            $bootstrap['encrypted_api_urls'] = $encrypted;
        }

        return $bootstrap;
    }

    public function applyToServer($user, array $server): array
    {
        $bindingPayload = $this->matchBindingPayload($user, $server);
        if ($bindingPayload) {
            if (!empty($bindingPayload['hide_node'])) {
                $server['app_domain_hidden'] = 1;
                return $server;
            }
            $server['host'] = $bindingPayload['domain'];
            if ((int) ($bindingPayload['port'] ?? 0) > 0) {
                $server['port'] = (int) $bindingPayload['port'];
            }
            return $server;
        }

        $rule = $this->matchRule($user, $server);
        if ($rule && (int) $rule->replace_node_host === 1) {
            $server['host'] = $this->normalizeHost($rule->domain);
            if ((int) ($rule->port ?? 0) > 0) {
                $server['port'] = (int) $rule->port;
            }
            return $server;
        }

        if ((int) config('v2board.app_domain_rule_enable', 0) === 1 && $this->rulesTableExists()) {
            return $server;
        }

        if ((int) ($server['app_domain_replace'] ?? 1) !== 1) {
            return $server;
        }

        $replaceHost = $this->resolveGlobalReplaceHost();
        if ($replaceHost !== '') {
            $server['host'] = $replaceHost;
        }

        return $server;
    }

    public function applyBehaviorEntranceToServer($user, array $server): array
    {
        $bindingPayload = $this->matchBindingPayload($user, $server, true);
        if (!$bindingPayload) {
            return $server;
        }

        if (!empty($bindingPayload['hide_node'])) {
            $server['app_domain_hidden'] = 1;
            return $server;
        }

        $server['host'] = $bindingPayload['domain'];
        if ((int) ($bindingPayload['port'] ?? 0) > 0) {
            $server['port'] = (int) $bindingPayload['port'];
        }

        return $server;
    }

    public function previewDispatchForUserId(int $userId, int $limit = 12): array
    {
        if ($userId <= 0 || !class_exists(User::class)) {
            return $this->emptyDispatchPreview('用户不存在');
        }

        $user = User::find($userId);
        if (!$user) {
            return $this->emptyDispatchPreview('用户不存在');
        }

        return $this->previewDispatchForUser($user, $limit);
    }

    public function previewDispatchForUser($user, int $limit = 12): array
    {
        $limit = max(1, min(50, $limit));
        $config = $this->getConfig();
        $snapshot = $this->riskSnapshotForUser($user);
        $servers = (new ServerService())->getAvailableServers($user);
        $rows = [];
        $hidden = 0;
        $changed = 0;

        foreach ($servers as $server) {
            if ((int) ($server['app_show'] ?? 1) !== 1) {
                continue;
            }

            $decision = $this->previewServerDecision($user, $server);
            if (!empty($decision['hidden'])) {
                $hidden++;
            }
            if (($decision['action'] ?? 'none') !== 'none') {
                $changed++;
            }
            if (count($rows) < $limit) {
                $rows[] = $decision;
            }
        }

        return [
            'enabled' => (int) ($config['app_domain_rule_enable'] ?? 0) === 1,
            'subscribe_url' => $this->buildSubscribeUrl((string) ($user->token ?? '')),
            'risk_level' => $snapshot['risk_level'] ?? '无风险',
            'risk_score' => (int) ($snapshot['risk_score'] ?? 0),
            'disposition_status' => $snapshot['disposition']['status'] ?? 'none',
            'total_nodes' => count($servers),
            'changed_nodes' => $changed,
            'hidden_nodes' => $hidden,
            'preview_limit' => $limit,
            'nodes' => $rows,
        ];
    }

    public function getGroups(): array
    {
        if (!$this->groupsTableExists()) {
            return [];
        }

        return AppDomainGroup::with('bindings')
            ->orderBy('sort', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()
            ->map(function ($group) {
                $payload = $group->toArray();
                $payload['bindings'] = $group->bindings
                    ->sort(function ($a, $b) {
                        return ((int) $a->sort <=> (int) $b->sort) ?: ((int) $a->id <=> (int) $b->id);
                    })
                    ->values()
                    ->toArray();
                return $payload;
            })
            ->toArray();
    }

    public function saveGroup(array $data): int
    {
        if (!$this->groupsTableExists()) {
            abort(500, '入口组表不存在，请先执行迁移脚本');
        }

        $payload = [
            'name' => trim((string) ($data['name'] ?? '')),
            'enable' => (int) ($data['enable'] ?? 1),
            'sort' => (int) ($data['sort'] ?? 0),
            'domain' => $this->normalizeHost($data['domain'] ?? ''),
            'user_group_ids' => $this->normalizeIds($data['user_group_ids'] ?? []),
            'plan_ids' => $this->normalizeIds($data['plan_ids'] ?? []),
            'risk_levels' => $this->normalizeRiskLevels($data['risk_levels'] ?? []),
            'disposition_statuses' => $this->normalizeDispositionStatuses($data['disposition_statuses'] ?? []),
            'hide_matched_nodes' => (int) ($data['hide_matched_nodes'] ?? 0),
            'remark' => trim((string) ($data['remark'] ?? '')),
        ];

        if ($payload['name'] === '') {
            abort(500, '入口组名称不能为空');
        }
        if ($payload['domain'] === '') {
            abort(500, '入口域名不能为空');
        }

        if (!empty($data['id'])) {
            $group = AppDomainGroup::find((int) $data['id']);
            if (!$group) {
                abort(500, '入口组不存在');
            }
            $group->update($payload);
            return (int) $group->id;
        }

        return (int) AppDomainGroup::create($payload)->id;
    }

    public function dropGroup(int $id): bool
    {
        if (!$this->groupsTableExists() || !$this->bindingsTableExists()) {
            abort(500, '入口组表不存在，请先执行迁移脚本');
        }

        $group = AppDomainGroup::find($id);
        if (!$group) {
            abort(500, '入口组不存在');
        }

        DB::beginTransaction();
        try {
            AppDomainBinding::where('group_id', $id)->delete();
            $group->delete();
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, '入口组删除失败');
        }

        return true;
    }

    public function saveBinding(array $data): int
    {
        if (!$this->groupsTableExists() || !$this->bindingsTableExists()) {
            abort(500, '入口绑定表不存在，请先执行迁移脚本');
        }

        $serverType = trim((string) ($data['server_type'] ?? ''));
        if (!in_array($serverType, self::SUPPORTED_SERVER_TYPES, true)) {
            abort(500, '节点类型不正确');
        }

        $payload = [
            'group_id' => (int) ($data['group_id'] ?? 0),
            'enable' => (int) ($data['enable'] ?? 1),
            'sort' => (int) ($data['sort'] ?? 0),
            'server_type' => $serverType,
            'server_id' => (int) ($data['server_id'] ?? 0),
            'port' => $this->normalizePort($data['port'] ?? null),
            'remark' => trim((string) ($data['remark'] ?? '')),
        ];

        if (!$payload['group_id'] || !AppDomainGroup::where('id', $payload['group_id'])->exists()) {
            abort(500, '入口组不存在');
        }
        if (!$payload['server_id']) {
            abort(500, '请选择节点');
        }

        $query = AppDomainBinding::where('group_id', $payload['group_id'])
            ->where('server_type', $payload['server_type'])
            ->where('server_id', $payload['server_id']);
        if (!empty($data['id'])) {
            $query->where('id', '<>', (int) $data['id']);
        }
        if ($query->exists()) {
            abort(500, '该入口组已绑定这个节点');
        }

        if (!empty($data['id'])) {
            $binding = AppDomainBinding::find((int) $data['id']);
            if (!$binding) {
                abort(500, '入口绑定不存在');
            }
            $binding->update($payload);
            return (int) $binding->id;
        }

        return (int) AppDomainBinding::create($payload)->id;
    }

    public function dropBinding(int $id): bool
    {
        if (!$this->bindingsTableExists()) {
            abort(500, '入口绑定表不存在，请先执行迁移脚本');
        }

        $binding = AppDomainBinding::find($id);
        if (!$binding) {
            abort(500, '入口绑定不存在');
        }

        return (bool) $binding->delete();
    }

    public function getRules(): array
    {
        if (!$this->rulesTableExists()) {
            return [];
        }

        return AppDomainRule::orderBy('sort', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()
            ->toArray();
    }

    public function saveRule(array $data): bool
    {
        if (!$this->rulesTableExists()) {
            abort(500, 'App域名规则表不存在，请先执行迁移脚本');
        }

        $payload = [
            'name' => trim((string) $data['name']),
            'enable' => (int) $data['enable'],
            'sort' => (int) ($data['sort'] ?? 0),
            'domain' => $this->normalizeHost($data['domain'] ?? ''),
            'port' => $this->normalizePort($data['port'] ?? null),
            'user_group_ids' => $this->normalizeIds($data['user_group_ids'] ?? []),
            'plan_ids' => $this->normalizeIds($data['plan_ids'] ?? []),
            'server_types' => $this->normalizeStrings($data['server_types'] ?? []),
            'server_ids' => $this->normalizeIds($data['server_ids'] ?? []),
            'protocols' => $this->normalizeStrings($data['protocols'] ?? []),
            'replace_node_host' => (int) $data['replace_node_host'],
            'replace_subscribe_host' => (int) $data['replace_subscribe_host'],
            'remark' => trim((string) ($data['remark'] ?? '')),
        ];

        if ($payload['domain'] === '') {
            abort(500, '入口域名不能为空');
        }

        if (!empty($data['id'])) {
            $rule = AppDomainRule::find($data['id']);
            if (!$rule) {
                abort(500, 'App域名规则不存在');
            }
            return (bool) $rule->update($payload);
        }

        return (bool) AppDomainRule::create($payload);
    }

    public function dropRule(int $id): bool
    {
        if (!$this->rulesTableExists()) {
            abort(500, 'App域名规则表不存在，请先执行迁移脚本');
        }

        $rule = AppDomainRule::find($id);
        if (!$rule) {
            abort(500, 'App域名规则不存在');
        }

        return (bool) $rule->delete();
    }

    public function sortRules(array $ruleIds): bool
    {
        if (!$this->rulesTableExists()) {
            abort(500, 'App域名规则表不存在，请先执行迁移脚本');
        }

        DB::beginTransaction();
        try {
            foreach (array_values($ruleIds) as $index => $id) {
                AppDomainRule::where('id', (int) $id)->update(['sort' => $index + 1]);
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, '规则排序保存失败');
        }

        return true;
    }

    public function getOptions(): array
    {
        return [
            'server_types' => self::SUPPORTED_SERVER_TYPES,
            'protocols' => self::SUPPORTED_SERVER_TYPES,
            'user_groups' => class_exists(ServerGroup::class) && $this->tableExists('v2_server_group') ? ServerGroup::orderBy('id', 'ASC')->get(['id', 'name'])->toArray() : [],
            'plans' => class_exists(Plan::class) && $this->tableExists('v2_plan') ? Plan::orderBy('sort', 'ASC')->get(['id', 'name'])->toArray() : [],
            'nodes' => $this->getNodeOptions(),
            'risk_levels' => SubscribeMonitorService::RISK_LEVELS,
            'disposition_statuses' => array_map(function ($status) {
                return [
                    'id' => $status,
                    'name' => $this->dispositionStatusLabel($status),
                ];
            }, SubscribeMonitorService::DISPOSITION_STATUSES),
            'rules_table_exists' => $this->rulesTableExists(),
            'groups_table_exists' => $this->groupsTableExists(),
            'bindings_table_exists' => $this->bindingsTableExists(),
        ];
    }

    public function matchRule($user, array $server): ?AppDomainRule
    {
        if ((int) config('v2board.app_domain_rule_enable', 0) !== 1 || !$this->rulesTableExists()) {
            return null;
        }

        $rules = AppDomainRule::where('enable', 1)
            ->where('replace_node_host', 1)
            ->orderBy('sort', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();

        foreach ($rules as $rule) {
            if ($this->ruleMatches($rule, $user, $server)) {
                return $rule;
            }
        }

        return null;
    }

    public function normalizeHost(?string $host): string
    {
        $host = trim((string) $host);
        $host = preg_replace('#^https?://#i', '', $host);
        return rtrim($host, '/');
    }

    public function normalizeEndpoint(?string $endpoint): string
    {
        $endpoint = trim((string) $endpoint);
        if ($endpoint === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $endpoint)) {
            $endpoint = $this->defaultSchemeForEndpoint($endpoint) . '://' . $endpoint;
        }

        $parts = parse_url($endpoint);
        if (!$parts || empty($parts['host'])) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if (!in_array($scheme, ['http', 'https'], true)) {
            $scheme = 'https';
        }
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return sprintf('%s://%s%s', $scheme, $host, $port);
    }

    public function normalizePath(?string $path): string
    {
        $path = trim((string) $path);
        if ($path === '') {
            $path = '/api/v1/client/custom_app/subscribe';
        }
        return '/' . ltrim($path, '/');
    }

    public function normalizeHosts(array $hosts): array
    {
        return array_values(array_filter(array_map(function ($host) {
            return $this->normalizeHost($host);
        }, $hosts)));
    }

    public function normalizeEndpoints(array $endpoints): array
    {
        return array_values(array_filter(array_map(function ($endpoint) {
            return $this->normalizeEndpoint($endpoint);
        }, $endpoints)));
    }

    public function getApiDomainHosts(): array
    {
        return $this->normalizeEndpoints((array) config('v2board.app_api_domain_hosts', []));
    }

    protected function resolveGlobalReplaceHost(): string
    {
        if ((int) config('v2board.app_domain_enable', 0) !== 1) {
            return '';
        }

        return $this->normalizeHost(config('v2board.app_domain_replace_host', ''));
    }

    protected function matchSubscribeRule($user = null): ?AppDomainRule
    {
        if ((int) config('v2board.app_domain_rule_enable', 0) !== 1 || !$this->rulesTableExists()) {
            return null;
        }

        $rules = AppDomainRule::where('enable', 1)
            ->where('replace_subscribe_host', 1)
            ->orderBy('sort', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();

        if (!$user) {
            return $rules->first();
        }

        foreach ($rules as $rule) {
            if ($this->ruleMatchesUser($rule, $user)) {
                return $rule;
            }
        }

        return null;
    }

    protected function ruleMatches(AppDomainRule $rule, $user, array $server): bool
    {
        $serverType = (string) ($server['type'] ?? ($server['protocol'] ?? ''));
        if (!$this->ruleMatchesUser($rule, $user)) {
            return false;
        }
        if (!$this->matchesScope($rule->server_types, [$serverType])) {
            return false;
        }
        if (!$this->matchesScope($rule->server_ids, [(int) ($server['id'] ?? 0)])) {
            return false;
        }
        if (!$this->matchesScope($rule->protocols, [$serverType])) {
            return false;
        }

        return true;
    }

    protected function ruleMatchesUser(AppDomainRule $rule, $user): bool
    {
        if (!$this->matchesScope($rule->user_group_ids, [(int) ($user->group_id ?? 0)])) {
            return false;
        }
        if (!$this->matchesScope($rule->plan_ids, [(int) ($user->plan_id ?? 0)])) {
            return false;
        }

        return true;
    }

    protected function groupMatchesUser(AppDomainGroup $group, $user): bool
    {
        if (!$this->matchesScope($group->user_group_ids, [(int) ($user->group_id ?? 0)])) {
            return false;
        }
        if (!$this->matchesScope($group->plan_ids, [(int) ($user->plan_id ?? 0)])) {
            return false;
        }
        $riskLevels = is_array($group->risk_levels) ? $group->risk_levels : [];
        $dispositionStatuses = is_array($group->disposition_statuses) ? $group->disposition_statuses : [];
        if ($riskLevels || $dispositionStatuses) {
            $snapshot = $this->riskSnapshotForUser($user);
            if (!$this->matchesScope($riskLevels, [$snapshot['risk_level'] ?? '无风险'])) {
                return false;
            }
            $status = $snapshot['disposition']['status'] ?? 'none';
            if (!$this->matchesScope($dispositionStatuses, [$status])) {
                return false;
            }
        }

        return true;
    }

    protected function matchBindingPayload($user, array $server, bool $onlyBehaviorScoped = false): ?array
    {
        if ((int) config('v2board.app_domain_rule_enable', 0) !== 1 || !$this->groupsTableExists() || !$this->bindingsTableExists()) {
            return null;
        }

        $serverType = (string) ($server['type'] ?? ($server['protocol'] ?? ''));
        $serverId = (int) ($server['id'] ?? 0);
        if ($serverType === '' || !$serverId) {
            return null;
        }

        $groups = AppDomainGroup::with(['bindings' => function ($query) use ($serverType, $serverId) {
                $query->where('enable', 1)
                    ->where('server_type', $serverType)
                    ->where('server_id', $serverId)
                    ->orderBy('sort', 'ASC')
                    ->orderBy('id', 'ASC');
            }])
            ->where('enable', 1)
            ->orderBy('sort', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();

        if (!$onlyBehaviorScoped) {
            $groups = $groups->sort(function ($a, $b) {
                $aBehavior = $this->groupHasBehaviorScope($a) ? 0 : 1;
                $bBehavior = $this->groupHasBehaviorScope($b) ? 0 : 1;

                return ($aBehavior <=> $bBehavior)
                    ?: ((int) $a->sort <=> (int) $b->sort)
                    ?: ((int) $a->id <=> (int) $b->id);
            })->values();
        }

        foreach ($groups as $group) {
            if ($onlyBehaviorScoped && !$this->groupHasBehaviorScope($group)) {
                continue;
            }
            if (!$this->groupMatchesUser($group, $user) || $group->bindings->isEmpty()) {
                continue;
            }
            $binding = $group->bindings->first();
            return [
                'domain' => $this->normalizeHost($group->domain),
                'port' => $binding->port,
                'group_id' => (int) $group->id,
                'group_name' => $group->name,
                'binding_id' => (int) $binding->id,
                'hide_node' => (int) ($group->hide_matched_nodes ?? 0) === 1,
            ];
        }

        return null;
    }

    protected function groupHasBehaviorScope(AppDomainGroup $group): bool
    {
        $riskLevels = is_array($group->risk_levels) ? $group->risk_levels : [];
        $dispositionStatuses = is_array($group->disposition_statuses) ? $group->disposition_statuses : [];

        return !empty($riskLevels) || !empty($dispositionStatuses);
    }

    protected function matchesScope($scope, array $values): bool
    {
        $scope = is_array($scope) ? array_values(array_filter($scope, function ($item) {
            return $item !== '' && $item !== null;
        })) : [];

        if (empty($scope)) {
            return true;
        }

        foreach ($values as $value) {
            if (in_array($value, $scope, true) || in_array((string) $value, $scope, true) || in_array((int) $value, $scope, true)) {
                return true;
            }
        }

        return false;
    }

    protected function normalizeIds($ids): array
    {
        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) {
            return $id > 0;
        })));
    }

    protected function normalizeStrings($items): array
    {
        if (!is_array($items)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(function ($item) {
            return trim((string) $item);
        }, $items))));
    }

    protected function normalizeRiskLevels($items): array
    {
        $items = $this->normalizeStrings($items);
        return array_values(array_filter($items, function ($item) {
            return in_array($item, SubscribeMonitorService::RISK_LEVELS, true);
        }));
    }

    protected function normalizeDispositionStatuses($items): array
    {
        $items = $this->normalizeStrings($items);
        return array_values(array_filter($items, function ($item) {
            return in_array($item, SubscribeMonitorService::DISPOSITION_STATUSES, true);
        }));
    }

    protected function normalizePort($port): ?int
    {
        if ($port === null || $port === '') {
            return null;
        }

        $port = (int) $port;
        return $port >= 1 && $port <= 65535 ? $port : null;
    }

    protected function rulesTableExists(): bool
    {
        return $this->tableExists('v2_app_domain_rules');
    }

    protected function groupsTableExists(): bool
    {
        return $this->tableExists('v2_app_domain_groups');
    }

    protected function bindingsTableExists(): bool
    {
        return $this->tableExists('v2_app_domain_bindings');
    }

    protected function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function findUserByToken(string $token)
    {
        if ($token === '' || !class_exists(User::class)) {
            return null;
        }

        return User::where('token', $token)->first();
    }

    protected function riskSnapshotForUser($user): array
    {
        $userId = (int) ($user->id ?? 0);
        if ($userId <= 0) {
            return [
                'risk_level' => '无风险',
                'risk_score' => 0,
                'disposition' => ['status' => 'none'],
            ];
        }
        if (!isset(self::$riskSnapshots[$userId])) {
            self::$riskSnapshots[$userId] = (new SubscribeMonitorService())->riskSnapshotForUser($user);
        }
        return self::$riskSnapshots[$userId];
    }

    protected function previewServerDecision($user, array $server): array
    {
        $originalHost = (string) ($server['host'] ?? '');
        $originalPort = $server['port'] ?? ($server['mport'] ?? '');
        $serverType = (string) ($server['type'] ?? ($server['protocol'] ?? ''));
        $serverId = (int) ($server['id'] ?? 0);
        $base = [
            'server_id' => $serverId,
            'server_type' => $serverType,
            'name' => (string) ($server['name'] ?? ''),
            'original_host' => $originalHost,
            'original_port' => $originalPort,
            'final_host' => $originalHost,
            'final_port' => $originalPort,
            'action' => 'none',
            'action_label' => '保持原入口',
            'group_id' => null,
            'group_name' => null,
            'rule_id' => null,
            'rule_name' => null,
            'hidden' => false,
        ];

        $bindingPayload = $this->matchBindingPayload($user, $server);
        if ($bindingPayload) {
            $base['action'] = !empty($bindingPayload['hide_node']) ? 'group_hidden' : 'group_binding';
            $base['action_label'] = !empty($bindingPayload['hide_node']) ? '入口组隐藏节点' : '入口组替换';
            $base['group_id'] = $bindingPayload['group_id'] ?? null;
            $base['group_name'] = $bindingPayload['group_name'] ?? null;
            $base['final_host'] = $bindingPayload['domain'] ?: $originalHost;
            $base['final_port'] = (int) ($bindingPayload['port'] ?? 0) > 0 ? (int) $bindingPayload['port'] : $originalPort;
            $base['hidden'] = !empty($bindingPayload['hide_node']);
            return $base;
        }

        $rule = $this->matchRule($user, $server);
        if ($rule && (int) $rule->replace_node_host === 1) {
            $base['action'] = 'rule';
            $base['action_label'] = '规则替换';
            $base['rule_id'] = (int) $rule->id;
            $base['rule_name'] = $rule->name;
            $base['final_host'] = $this->normalizeHost($rule->domain) ?: $originalHost;
            $base['final_port'] = (int) ($rule->port ?? 0) > 0 ? (int) $rule->port : $originalPort;
            return $base;
        }

        if ((int) config('v2board.app_domain_rule_enable', 0) === 1 && $this->rulesTableExists()) {
            return $base;
        }

        if ((int) ($server['app_domain_replace'] ?? 1) !== 1) {
            return $base;
        }

        $replaceHost = $this->resolveGlobalReplaceHost();
        if ($replaceHost !== '') {
            $base['action'] = 'global';
            $base['action_label'] = '全局替换';
            $base['final_host'] = $replaceHost;
        }

        return $base;
    }

    protected function emptyDispatchPreview(string $reason): array
    {
        return [
            'enabled' => false,
            'reason' => $reason,
            'subscribe_url' => '',
            'risk_level' => '无风险',
            'risk_score' => 0,
            'disposition_status' => 'none',
            'total_nodes' => 0,
            'changed_nodes' => 0,
            'hidden_nodes' => 0,
            'preview_limit' => 0,
            'nodes' => [],
        ];
    }

    protected function dispositionStatusLabel(string $status): string
    {
        return [
            'none' => '未处置',
            'watch' => '加入观察',
            'handled' => '已处理',
            'whitelist' => '白名单',
            'freeze_suggested' => '建议冻结',
            'blacklist_suggested' => '建议拉黑',
        ][$status] ?? $status;
    }

    protected function getNodeOptions(): array
    {
        $nodes = [];
        foreach (self::SERVER_OPTION_TABLES as $type => $table) {
            if (!$this->tableExists($table)) {
                continue;
            }
            $columns = ['id', 'name'];
            if (Schema::hasColumn($table, 'host')) {
                $columns[] = 'host';
            }
            if (Schema::hasColumn($table, 'port')) {
                $columns[] = 'port';
            }
            $query = DB::table($table)->select($columns);
            if (Schema::hasColumn($table, 'sort')) {
                $query->orderBy('sort', 'ASC');
            }
            $rows = $query->orderBy('id', 'ASC')->get();
            foreach ($rows as $row) {
                $nodes[] = [
                    'id' => (int) $row->id,
                    'type' => $type,
                    'name' => $row->name,
                    'host' => $row->host ?? '',
                    'port' => $row->port ?? '',
                    'label' => sprintf('%s #%s %s', $type, $row->id, $row->name),
                ];
            }
        }

        return $nodes;
    }

    protected function buildApiUrls(array $apiHosts): array
    {
        return array_map(function ($host) {
            return sprintf('%s/api/v1/client/app', $host);
        }, $apiHosts);
    }

    protected function buildEncryptedApiUrls(array $apiUrls): ?string
    {
        if ((int) config('v2board.app_api_domain_encrypt_enable', 0) !== 1) {
            return null;
        }

        return Helper::encryptAppPayload($apiUrls, (string) config('v2board.app_api_domain_encrypt_key', ''));
    }

    protected function defaultSchemeForEndpoint(string $endpoint): string
    {
        $host = preg_replace('#/.*$#', '', trim($endpoint));
        if (preg_match('#^(\d{1,3}\.){3}\d{1,3}(:\d+)?$#', $host)) {
            return 'http';
        }
        if (preg_match('#:(?!443$)\d+$#', $host)) {
            return 'http';
        }

        return 'https';
    }

    protected function clearConfigCache(string $configPath): void
    {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($configPath, true);
        } elseif (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        $_SERVER['PHP_SELF'] = $_SERVER['PHP_SELF'] ?? 'artisan';
        $_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? 'artisan';
        Artisan::call('config:clear');
        Artisan::call('config:cache');

        if (Cache::has('WEBMANPID') && function_exists('posix_kill') && defined('SIGUSR1')) {
            $pid = (int) Cache::get('WEBMANPID');
            @posix_kill($pid, SIGUSR1);
        }
    }
}
