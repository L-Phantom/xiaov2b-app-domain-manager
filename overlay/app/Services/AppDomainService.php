<?php

namespace App\Services;

use App\Models\AppDomainRule;
use App\Models\AppDomainBinding;
use App\Models\AppDomainGroup;
use App\Models\AppDomainAssignment;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class AppDomainService
{
    protected static $entryAssignments = [];

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

    protected const GLOBAL_REPLACE_AUDIT_TABLE = 'v2_app_domain_replace_batches';
    protected const ASSIGNMENT_TABLE = 'v2_app_domain_assignments';
    protected const DEFAULT_EXPERIMENT_GROUP = 'Entry Cohort Test D';

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

    public function applyAssignedEntranceToServer($user, array $server): array
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
            'hide_matched_nodes' => (int) ($data['hide_matched_nodes'] ?? 0),
            'assignment_only' => (int) ($data['assignment_only'] ?? 0),
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
            'rules_table_exists' => $this->rulesTableExists(),
            'groups_table_exists' => $this->groupsTableExists(),
            'bindings_table_exists' => $this->bindingsTableExists(),
            'assignments_table_exists' => $this->assignmentTableExists(),
            'global_replace_audit_table_exists' => $this->globalReplaceAuditTableExists(),
        ];
    }

    public function previewGlobalHostReplace(string $oldHost, string $newHost): array
    {
        [$oldHost, $newHost] = $this->validateGlobalReplaceHosts($oldHost, $newHost);
        $changes = $this->collectGlobalHostChanges($oldHost, $newHost);

        return $this->formatGlobalReplacePreview($oldHost, $newHost, $changes);
    }

    public function applyGlobalHostReplace(array $data, $operator = null): array
    {
        if (!$this->globalReplaceAuditTableExists()) {
            abort(500, '全局替换审计表不存在，请先执行迁移脚本');
        }

        [$oldHost, $newHost] = $this->validateGlobalReplaceHosts(
            (string) ($data['old_host'] ?? ''),
            (string) ($data['new_host'] ?? '')
        );
        $changes = $this->collectGlobalHostChanges($oldHost, $newHost);
        $preview = $this->formatGlobalReplacePreview($oldHost, $newHost, $changes);
        $previewToken = trim((string) ($data['preview_token'] ?? ''));
        $confirmation = trim((string) ($data['confirmation'] ?? ''));

        if ($preview['total_changes'] <= 0) {
            abort(422, '当前没有可替换的入口记录');
        }
        if ($previewToken === '' || !hash_equals($preview['preview_token'], $previewToken)) {
            abort(409, '数据库内容已变化，请重新预览后再执行');
        }
        if ($confirmation !== $newHost) {
            abort(422, '确认内容不正确，请完整输入新入口域名');
        }

        $batchUuid = $this->newGlobalReplaceBatchUuid();
        $operatorId = $this->globalReplaceOperatorValue($operator, 'id');
        $operatorEmail = $this->globalReplaceOperatorValue($operator, 'email');
        $configChanges = array_values(array_filter($changes, function ($change) {
            return ($change['scope'] ?? '') === 'config';
        }));
        $configBefore = config('v2board', []);
        $configWasWritten = false;

        try {
            if ($configChanges) {
                $configAfter = $this->applyConfigSnapshotChanges($configBefore, $configChanges, 'after');
                $this->writeV2boardConfig($configAfter, false);
                $configWasWritten = true;
            }

            DB::transaction(function () use ($changes, $batchUuid, $oldHost, $newHost, $operatorId, $operatorEmail) {
                foreach ($changes as $change) {
                    if (($change['scope'] ?? '') === 'config') {
                        continue;
                    }
                    $updated = DB::table($change['table'])
                        ->where('id', (int) $change['id'])
                        ->where($change['column'], $change['before'])
                        ->update([
                            $change['column'] => $change['after'],
                            'updated_at' => time(),
                        ]);
                    if ($updated !== 1) {
                        throw new \RuntimeException(sprintf('记录已变化：%s #%s', $change['table'], $change['id']));
                    }
                }

                DB::table(self::GLOBAL_REPLACE_AUDIT_TABLE)->insert([
                    'batch_uuid' => $batchUuid,
                    'old_host' => $oldHost,
                    'new_host' => $newHost,
                    'status' => 'applied',
                    'change_count' => count($changes),
                    'snapshot' => json_encode($changes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'operator_id' => $operatorId,
                    'operator_email' => $operatorEmail,
                    'created_at' => time(),
                ]);
            });
        } catch (\Throwable $e) {
            if ($configWasWritten) {
                try {
                    $this->writeV2boardConfig($configBefore, false);
                } catch (\Throwable $ignored) {
                }
            }
            abort(409, '全局替换未执行：' . $e->getMessage());
        }

        if ($configWasWritten) {
            $this->clearConfigCache(base_path('/config/v2board.php'));
        }

        return [
            'batch_uuid' => $batchUuid,
            'old_host' => $oldHost,
            'new_host' => $newHost,
            'total_changes' => count($changes),
            'status' => 'applied',
        ];
    }

    public function getGlobalHostReplaceHistory(int $limit = 20): array
    {
        if (!$this->globalReplaceAuditTableExists()) {
            return [];
        }

        return DB::table(self::GLOBAL_REPLACE_AUDIT_TABLE)
            ->orderBy('id', 'DESC')
            ->limit(max(1, min(100, $limit)))
            ->get([
                'batch_uuid', 'old_host', 'new_host', 'status', 'change_count',
                'operator_email', 'created_at', 'rolled_back_at', 'rollback_operator_email',
            ])
            ->map(function ($row) {
                return [
                    'batch_uuid' => $row->batch_uuid,
                    'old_host' => $row->old_host,
                    'new_host' => $row->new_host,
                    'status' => $row->status,
                    'change_count' => (int) $row->change_count,
                    'operator_email' => $row->operator_email,
                    'created_at' => (int) $row->created_at,
                    'rolled_back_at' => $row->rolled_back_at !== null ? (int) $row->rolled_back_at : null,
                    'rollback_operator_email' => $row->rollback_operator_email,
                ];
            })
            ->toArray();
    }

    public function rollbackGlobalHostReplace(string $batchUuid, string $confirmation, $operator = null): array
    {
        if (!$this->globalReplaceAuditTableExists()) {
            abort(500, '全局替换审计表不存在，请先执行迁移脚本');
        }

        $batchUuid = trim($batchUuid);
        if ($batchUuid === '' || $confirmation !== $batchUuid) {
            abort(422, '回滚确认内容不正确');
        }
        $batch = DB::table(self::GLOBAL_REPLACE_AUDIT_TABLE)->where('batch_uuid', $batchUuid)->first();
        if (!$batch) {
            abort(404, '替换批次不存在');
        }
        if ($batch->status !== 'applied') {
            abort(409, '这个批次已经回滚，不能重复操作');
        }

        $changes = json_decode((string) $batch->snapshot, true);
        if (!is_array($changes) || !$changes) {
            abort(500, '批次快照损坏，已拒绝回滚');
        }
        $this->assertGlobalReplaceSnapshotState($changes, 'after');

        $configChanges = array_values(array_filter($changes, function ($change) {
            return ($change['scope'] ?? '') === 'config';
        }));
        $configBeforeRollback = config('v2board', []);
        $configWasWritten = false;
        $operatorId = $this->globalReplaceOperatorValue($operator, 'id');
        $operatorEmail = $this->globalReplaceOperatorValue($operator, 'email');

        try {
            if ($configChanges) {
                $configAfterRollback = $this->applyConfigSnapshotChanges($configBeforeRollback, $configChanges, 'before');
                $this->writeV2boardConfig($configAfterRollback, false);
                $configWasWritten = true;
            }

            DB::transaction(function () use ($changes, $batchUuid, $operatorId, $operatorEmail) {
                foreach ($changes as $change) {
                    if (($change['scope'] ?? '') === 'config') {
                        continue;
                    }
                    $updated = DB::table($change['table'])
                        ->where('id', (int) $change['id'])
                        ->where($change['column'], $change['after'])
                        ->update([
                            $change['column'] => $change['before'],
                            'updated_at' => time(),
                        ]);
                    if ($updated !== 1) {
                        throw new \RuntimeException(sprintf('记录已变化：%s #%s', $change['table'], $change['id']));
                    }
                }

                $updated = DB::table(self::GLOBAL_REPLACE_AUDIT_TABLE)
                    ->where('batch_uuid', $batchUuid)
                    ->where('status', 'applied')
                    ->update([
                        'status' => 'rolled_back',
                        'rolled_back_at' => time(),
                        'rollback_operator_id' => $operatorId,
                        'rollback_operator_email' => $operatorEmail,
                    ]);
                if ($updated !== 1) {
                    throw new \RuntimeException('批次状态已经变化');
                }
            });
        } catch (\Throwable $e) {
            if ($configWasWritten) {
                try {
                    $this->writeV2boardConfig($configBeforeRollback, false);
                } catch (\Throwable $ignored) {
                }
            }
            abort(409, '回滚未执行：' . $e->getMessage());
        }

        if ($configWasWritten) {
            $this->clearConfigCache(base_path('/config/v2board.php'));
        }

        return [
            'batch_uuid' => $batchUuid,
            'status' => 'rolled_back',
            'restored_changes' => count($changes),
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
        return true;
    }

    protected function matchBindingPayload($user, array $server, bool $onlyAssigned = false): ?array
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

        $assignment = $this->entryAssignmentForUser($user);
        if ($assignment) {
            $assignedGroup = $groups->first(function ($group) use ($assignment) {
                return (int) $group->id === (int) $assignment['group_id'];
            });
            if ($assignedGroup && !$assignedGroup->bindings->isEmpty()) {
                $binding = $assignedGroup->bindings->first();
                return [
                    'domain' => $this->normalizeHost($assignedGroup->domain),
                    'port' => $binding->port,
                    'group_id' => (int) $assignedGroup->id,
                    'group_name' => $assignedGroup->name,
                    'binding_id' => (int) $binding->id,
                    'hide_node' => (int) ($assignedGroup->hide_matched_nodes ?? 0) === 1,
                    'assignment_id' => (int) $assignment['id'],
                    'cohort' => (string) $assignment['cohort'],
                    'round_uuid' => (string) $assignment['round_uuid'],
                ];
            }
        }

        if ($onlyAssigned) {
            return null;
        }

        foreach ($groups as $group) {
            if ((int) ($group->assignment_only ?? 0) === 1) {
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

    protected function entryAssignmentForUser($user): ?array
    {
        if (!$this->assignmentTableExists()) {
            return null;
        }

        $userId = (int) (is_array($user) ? ($user['id'] ?? 0) : ($user->id ?? 0));
        if ($userId <= 0) {
            return null;
        }
        if (array_key_exists($userId, self::$entryAssignments)) {
            return self::$entryAssignments[$userId];
        }

        $assignment = AppDomainAssignment::where('user_id', $userId)
            ->where('enable', 1)
            ->first(['id', 'user_id', 'group_id', 'cohort', 'round_uuid']);
        if (!$assignment) {
            $assignment = $this->createDefaultExperimentAssignment($userId);
        }
        self::$entryAssignments[$userId] = $assignment ? [
            'id' => (int) $assignment->id,
            'user_id' => (int) $assignment->user_id,
            'group_id' => (int) $assignment->group_id,
            'cohort' => (string) $assignment->cohort,
            'round_uuid' => (string) $assignment->round_uuid,
        ] : null;

        return self::$entryAssignments[$userId];
    }

    protected function createDefaultExperimentAssignment(int $userId): ?AppDomainAssignment
    {
        $group = AppDomainGroup::where('name', self::DEFAULT_EXPERIMENT_GROUP)
            ->where('enable', 1)
            ->where('assignment_only', 1)
            ->first(['id']);
        if (!$group || !AppDomainBinding::where('group_id', $group->id)->where('enable', 1)->exists()) {
            return null;
        }

        $now = time();
        try {
            DB::transaction(function () use ($userId, $group, $now) {
                $existing = DB::table(self::ASSIGNMENT_TABLE)
                    ->where('user_id', $userId)
                    ->lockForUpdate()
                    ->first(['id']);
                $values = [
                    'group_id' => (int) $group->id,
                    'cohort' => 'test_d',
                    'round_uuid' => 'auto-default-' . gmdate('Ymd'),
                    'reason' => 'default_new_user_cold_lane',
                    'metrics' => json_encode(['source' => 'first_subscription'], JSON_UNESCAPED_SLASHES),
                    'enable' => 1,
                    'frozen_until' => $now + 86400,
                    'assigned_at' => $now,
                    'updated_at' => $now,
                ];
                if ($existing) {
                    DB::table(self::ASSIGNMENT_TABLE)->where('id', $existing->id)->update($values);
                    return;
                }
                $values['user_id'] = $userId;
                $values['created_at'] = $now;
                DB::table(self::ASSIGNMENT_TABLE)->insert($values);
            });
        } catch (\Throwable $error) {
            // A concurrent first subscription may have inserted the unique user row already.
            if (!DB::table(self::ASSIGNMENT_TABLE)->where('user_id', $userId)->where('enable', 1)->exists()) {
                throw $error;
            }
        }

        return AppDomainAssignment::where('user_id', $userId)
            ->where('enable', 1)
            ->first(['id', 'user_id', 'group_id', 'cohort', 'round_uuid']);
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

    protected function assignmentTableExists(): bool
    {
        return $this->tableExists(self::ASSIGNMENT_TABLE);
    }

    protected function globalReplaceAuditTableExists(): bool
    {
        return $this->tableExists(self::GLOBAL_REPLACE_AUDIT_TABLE);
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
            'assignment_id' => null,
            'cohort' => null,
            'round_uuid' => null,
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
            $base['assignment_id'] = $bindingPayload['assignment_id'] ?? null;
            $base['cohort'] = $bindingPayload['cohort'] ?? null;
            $base['round_uuid'] = $bindingPayload['round_uuid'] ?? null;
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
            'total_nodes' => 0,
            'changed_nodes' => 0,
            'hidden_nodes' => 0,
            'preview_limit' => 0,
            'nodes' => [],
        ];
    }

    protected function validateGlobalReplaceHosts(string $oldHost, string $newHost): array
    {
        $oldHost = strtolower($this->normalizeHost($oldHost));
        $newHost = strtolower($this->normalizeHost($newHost));

        foreach (['旧入口域名' => $oldHost, '新入口域名' => $newHost] as $label => $host) {
            if ($host === '') {
                abort(422, $label . '不能为空');
            }
            if (strlen($host) > 255 || strpos($host, '/') !== false || strpos($host, ':') !== false) {
                abort(422, $label . '只能填写域名或 IPv4，不要包含协议、端口和路径');
            }
            $validIp = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
            $validDomain = (bool) preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host);
            if (!$validIp && !$validDomain) {
                abort(422, $label . '格式不正确');
            }
        }
        if ($oldHost === $newHost) {
            abort(422, '新旧入口域名不能相同');
        }

        return [$oldHost, $newHost];
    }

    protected function collectGlobalHostChanges(string $oldHost, string $newHost): array
    {
        $changes = [];
        foreach (self::SERVER_OPTION_TABLES as $type => $table) {
            if (!$this->tableExists($table) || !Schema::hasColumn($table, 'host')) {
                continue;
            }
            $columns = ['id', 'host'];
            if (Schema::hasColumn($table, 'name')) {
                $columns[] = 'name';
            }
            $rows = DB::table($table)
                ->where('host', $oldHost)
                ->orderBy('id', 'ASC')
                ->get($columns);
            foreach ($rows as $row) {
                $changes[] = [
                    'scope' => 'node',
                    'table' => $table,
                    'column' => 'host',
                    'id' => (int) $row->id,
                    'type' => $type,
                    'name' => (string) ($row->name ?? ''),
                    'before' => (string) $row->host,
                    'after' => $newHost,
                ];
            }
        }

        foreach ([
            ['scope' => 'domain_group', 'table' => 'v2_app_domain_groups', 'type' => '入口组'],
            ['scope' => 'domain_rule', 'table' => 'v2_app_domain_rules', 'type' => '旧版入口规则'],
        ] as $target) {
            if (!$this->tableExists($target['table']) || !Schema::hasColumn($target['table'], 'domain')) {
                continue;
            }
            $rows = DB::table($target['table'])
                ->where('domain', $oldHost)
                ->orderBy('id', 'ASC')
                ->get(['id', 'name', 'domain']);
            foreach ($rows as $row) {
                $changes[] = [
                    'scope' => $target['scope'],
                    'table' => $target['table'],
                    'column' => 'domain',
                    'id' => (int) $row->id,
                    'type' => $target['type'],
                    'name' => (string) $row->name,
                    'before' => (string) $row->domain,
                    'after' => $newHost,
                ];
            }
        }

        $config = config('v2board', []);
        foreach (['app_domain_replace_host', 'app_domain_global_replace_host'] as $key) {
            if (isset($config[$key]) && strtolower($this->normalizeHost((string) $config[$key])) === $oldHost) {
                $changes[] = $this->globalReplaceConfigChange($key, $config[$key], $newHost);
            }
        }
        if (isset($config['app_domain_replace_map']) && is_array($config['app_domain_replace_map'])) {
            $replaceMap = $config['app_domain_replace_map'];
            $updatedMap = $this->replaceExactHostsInMap($replaceMap, $oldHost, $newHost);
            if ($updatedMap !== $replaceMap) {
                $changes[] = $this->globalReplaceConfigChange('app_domain_replace_map', $replaceMap, $updatedMap);
            }
        }

        return $changes;
    }

    protected function globalReplaceConfigChange(string $key, $before, $after): array
    {
        return [
            'scope' => 'config',
            'table' => 'config/v2board.php',
            'column' => $key,
            'id' => 0,
            'type' => '系统配置',
            'name' => $key,
            'before' => $before,
            'after' => $after,
        ];
    }

    protected function replaceExactHostsInMap(array $items, string $oldHost, string $newHost): array
    {
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach (['match_host', 'replace_host'] as $key) {
                if (isset($item[$key]) && strtolower($this->normalizeHost((string) $item[$key])) === $oldHost) {
                    $items[$index][$key] = $newHost;
                }
            }
        }

        return $items;
    }

    protected function formatGlobalReplacePreview(string $oldHost, string $newHost, array $changes): array
    {
        $typeCounts = [];
        $scopeCounts = [];
        foreach ($changes as $change) {
            $type = (string) ($change['type'] ?? '其他');
            $scope = (string) ($change['scope'] ?? 'other');
            $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
            $scopeCounts[$scope] = ($scopeCounts[$scope] ?? 0) + 1;
        }

        return [
            'old_host' => $oldHost,
            'new_host' => $newHost,
            'total_changes' => count($changes),
            'node_changes' => (int) ($scopeCounts['node'] ?? 0),
            'distribution_changes' => (int) (($scopeCounts['domain_group'] ?? 0) + ($scopeCounts['domain_rule'] ?? 0)),
            'config_changes' => (int) ($scopeCounts['config'] ?? 0),
            'type_counts' => $typeCounts,
            'changes' => $changes,
            'preview_token' => $this->globalReplacePreviewToken($oldHost, $newHost, $changes),
        ];
    }

    protected function globalReplacePreviewToken(string $oldHost, string $newHost, array $changes): string
    {
        $payload = json_encode([$oldHost, $newHost, $changes], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return hash_hmac('sha256', $payload, (string) config('app.key', 'app-domain-global-replace'));
    }

    protected function applyConfigSnapshotChanges(array $config, array $changes, string $valueKey): array
    {
        foreach ($changes as $change) {
            $config[$change['column']] = $change[$valueKey];
        }

        return $config;
    }

    protected function assertGlobalReplaceSnapshotState(array $changes, string $valueKey): void
    {
        $config = config('v2board', []);
        foreach ($changes as $change) {
            $expected = $change[$valueKey] ?? null;
            if (($change['scope'] ?? '') === 'config') {
                $current = $config[$change['column']] ?? null;
            } else {
                $row = DB::table($change['table'])->where('id', (int) $change['id'])->first();
                $current = $row ? $row->{$change['column']} : null;
            }
            if ($current !== $expected) {
                abort(409, sprintf('记录已被后续修改，拒绝回滚：%s %s', $change['table'], $change['name'] ?? ''));
            }
        }
    }

    protected function writeV2boardConfig(array $config, bool $clearCache = true): void
    {
        $configPath = base_path('/config/v2board.php');
        $contents = var_export($config, true);
        if (!File::put($configPath, "<?php\n\nreturn {$contents};\n")) {
            throw new \RuntimeException('写入 v2board 配置失败');
        }
        config(['v2board' => $config]);
        if ($clearCache) {
            $this->clearConfigCache($configPath);
        }
    }

    protected function newGlobalReplaceBatchUuid(): string
    {
        return sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(random_bytes(4)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(6))
        );
    }

    protected function globalReplaceOperatorValue($operator, string $key)
    {
        if (is_array($operator)) {
            return $operator[$key] ?? null;
        }
        if (is_object($operator)) {
            return $operator->{$key} ?? null;
        }

        return null;
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
