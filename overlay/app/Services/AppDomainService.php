<?php

namespace App\Services;

use App\Models\AppDomainRule;
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

    public function getConfig(): array
    {
        return [
            'app_domain_enable' => (int) config('v2board.app_domain_enable', 0),
            'app_domain_public_host' => $this->normalizeHost(config('v2board.app_domain_public_host', '')),
            'app_domain_subscribe_path' => $this->normalizePath(config('v2board.app_domain_subscribe_path', '/api/v1/client/custom_app/subscribe')),
            'app_domain_replace_host' => $this->normalizeHost(config('v2board.app_domain_replace_host', '')),
            'app_api_domain_enable' => (int) config('v2board.app_api_domain_enable', 0),
            'app_api_domain_hosts' => $this->normalizeHosts((array) config('v2board.app_api_domain_hosts', [])),
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
                ? sprintf('https://%s%s?token=%s', $publicHost, $subscribePath, $token)
                : sprintf('%s?token=%s', $subscribePath, $token),
            'bootstrap_path' => '/api/v1/client/app/bootstrap',
            'app_config_path' => '/api/v1/client/app/getConfig',
            'app_version_path' => '/api/v1/client/app/getVersion',
            'api_urls' => array_map(function ($host) {
                return sprintf('https://%s/api/v1/client/app/bootstrap', $host);
            }, $config['app_api_domain_hosts'])
        ];

        return $config;
    }

    public function saveConfig(array $data): bool
    {
        $config = config('v2board', []);
        $publicHost = $this->normalizeHost($data['app_domain_public_host'] ?? '');
        $replaceHost = $this->normalizeHost($data['app_domain_replace_host'] ?? '');
        $apiHosts = $this->normalizeHosts((array) ($data['app_api_domain_hosts'] ?? []));

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
            ? $this->normalizeHost($rule->domain)
            : $config['app_domain_public_host'];

        if ($host !== '') {
            return 'https://' . $host . $path;
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
        if ((int) ($server['app_domain_replace'] ?? 1) !== 1) {
            return $server;
        }

        $rule = $this->matchRule($user, $server);
        $replaceHost = $rule && (int) $rule->replace_node_host === 1
            ? $this->normalizeHost($rule->domain)
            : $this->resolveGlobalReplaceHost();

        if ($replaceHost !== '') {
            $server['host'] = $replaceHost;
        }

        return $server;
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
            'rules_table_exists' => $this->rulesTableExists(),
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

    public function getApiDomainHosts(): array
    {
        return $this->normalizeHosts((array) config('v2board.app_api_domain_hosts', []));
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

    protected function rulesTableExists(): bool
    {
        return $this->tableExists('v2_app_domain_rules');
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

    protected function buildApiUrls(array $apiHosts): array
    {
        return array_map(function ($host) {
            return sprintf('https://%s/api/v1/client/app', $host);
        }, $apiHosts);
    }

    protected function buildEncryptedApiUrls(array $apiUrls): ?string
    {
        if ((int) config('v2board.app_api_domain_encrypt_enable', 0) !== 1) {
            return null;
        }

        return Helper::encryptAppPayload($apiUrls, (string) config('v2board.app_api_domain_encrypt_key', ''));
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
