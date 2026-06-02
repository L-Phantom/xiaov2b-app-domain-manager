<?php

namespace App\Services;

use App\Utils\Helper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class AppDomainService
{
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
        if ($config['app_domain_public_host'] !== '') {
            return 'https://' . $config['app_domain_public_host'] . $path;
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
            'replace_host' => $this->normalizeHost(config('v2board.app_domain_replace_host', '')),
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
        $replaceEnabled = (int) config('v2board.app_domain_enable', 0) === 1;
        $replaceHost = $this->normalizeHost(config('v2board.app_domain_replace_host', ''));
        if ($replaceEnabled && $replaceHost !== '' && (int) ($server['app_domain_replace'] ?? 1) === 1) {
            $server['host'] = $replaceHost;
        }

        return $server;
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
