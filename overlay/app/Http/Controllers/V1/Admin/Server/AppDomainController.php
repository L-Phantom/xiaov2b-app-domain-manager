<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class AppDomainController extends Controller
{
    public function fetch()
    {
        $hosts = array_values(array_filter(array_map('trim', (array) config('v2board.app_api_domain_hosts', []))));
        $token = 'YOUR_TOKEN';
        $subscribePath = $this->normalizePath(config('v2board.app_domain_subscribe_path', '/api/v1/client/custom_app/subscribe'));
        $publicHost = trim((string) config('v2board.app_domain_public_host', ''));
        $subscribeExample = $publicHost
            ? sprintf('https://%s%s?token=%s', $publicHost, $subscribePath, $token)
            : sprintf('%s?token=%s', $subscribePath, $token);

        return response([
            'data' => [
                'app_domain_enable' => (int) config('v2board.app_domain_enable', 0),
                'app_domain_public_host' => $publicHost,
                'app_domain_subscribe_path' => $subscribePath,
                'app_domain_replace_host' => trim((string) config('v2board.app_domain_replace_host', '')),
                'app_api_domain_enable' => (int) config('v2board.app_api_domain_enable', 0),
                'app_api_domain_hosts' => $hosts,
                'app_api_domain_encrypt_enable' => (int) config('v2board.app_api_domain_encrypt_enable', 0),
                'app_api_domain_encrypt_key' => trim((string) config('v2board.app_api_domain_encrypt_key', '')),
                'preview' => [
                    'subscribe_example' => $subscribeExample,
                    'bootstrap_path' => '/api/v1/client/app/bootstrap',
                    'app_config_path' => '/api/v1/client/app/getConfig',
                    'app_version_path' => '/api/v1/client/app/getVersion',
                    'api_urls' => array_map(function ($host) {
                        return sprintf('https://%s/api/v1/client/app/bootstrap', $host);
                    }, $hosts)
                ]
            ]
        ]);
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'app_domain_enable' => 'required|in:0,1',
            'app_domain_public_host' => 'nullable|string',
            'app_domain_subscribe_path' => 'required|regex:/^\\//',
            'app_domain_replace_host' => 'nullable|string',
            'app_api_domain_enable' => 'required|in:0,1',
            'app_api_domain_hosts' => 'nullable|array',
            'app_api_domain_hosts.*' => 'nullable|string',
            'app_api_domain_encrypt_enable' => 'required|in:0,1',
            'app_api_domain_encrypt_key' => 'nullable|string',
        ], [
            'app_domain_subscribe_path.regex' => 'App订阅路径必须以/开头',
        ]);

        $config = config('v2board', []);
        $publicHost = $this->normalizeHost($data['app_domain_public_host'] ?? '');
        $replaceHost = $this->normalizeHost($data['app_domain_replace_host'] ?? '');
        $apiHosts = array_values(array_filter(array_map([$this, 'normalizeHost'], (array) ($data['app_api_domain_hosts'] ?? []))));

        $config['app_domain_enable'] = (int) $data['app_domain_enable'];
        $config['app_domain_public_host'] = $publicHost;
        $config['app_domain_subscribe_path'] = $this->normalizePath($data['app_domain_subscribe_path']);
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
            abort(500, 'App域名配置保存失败');
        }

        config(['v2board' => $config]);

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($configPath, true);
        } elseif (function_exists('opcache_reset') && opcache_reset() === false) {
            abort(500, '缓存清除失败，请检查opcache状态');
        }

        $_SERVER['PHP_SELF'] = $_SERVER['PHP_SELF'] ?? 'artisan';
        $_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? 'artisan';
        Artisan::call('config:clear');
        Artisan::call('config:cache');

        if (Cache::has('WEBMANPID')) {
            $pid = (int) Cache::get('WEBMANPID');
            @posix_kill($pid, SIGUSR1);
        }

        return response([
            'data' => true
        ]);
    }

    protected function normalizeHost(?string $host): string
    {
        $host = trim((string) $host);
        $host = preg_replace('#^https?://#i', '', $host);
        return rtrim($host, '/');
    }

    protected function normalizePath(?string $path): string
    {
        $path = trim((string) $path);
        if ($path === '') {
            $path = '/api/v1/client/custom_app/subscribe';
        }
        return '/' . ltrim($path, '/');
    }
}
