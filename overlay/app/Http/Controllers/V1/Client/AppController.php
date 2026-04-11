<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

class AppController extends Controller
{
    public function getConfig(Request $request)
    {
        $servers = [];
        $user = $request->user;
        $userService = new UserService();
        if ($userService->isAvailable($user)) {
            $serverService = new ServerService();
            $servers = $serverService->getAvailableAppServers($user);
        }
        $defaultConfig = base_path() . '/resources/rules/app.clash.yaml';
        $customConfig = base_path() . '/resources/rules/custom.app.clash.yaml';
        if (File::exists($customConfig)) {
            $config = Yaml::parseFile($customConfig);
        } else {
            $config = Yaml::parseFile($defaultConfig);
        }
        $proxy = [];
        $proxies = [];

        foreach ($servers as $item) {
            if ($item['type'] === 'shadowsocks'
                && in_array($item['cipher'], [
                    'aes-128-gcm',
                    'aes-192-gcm',
                    'aes-256-gcm',
                    'chacha20-ietf-poly1305'
                ])
            ) {
                array_push($proxy, \App\Protocols\Clash::buildShadowsocks($user['uuid'], $item));
                array_push($proxies, $item['name']);
            }
            if ($item['type'] === 'vmess') {
                array_push($proxy, \App\Protocols\Clash::buildVmess($user['uuid'], $item));
                array_push($proxies, $item['name']);
            }
            if ($item['type'] === 'trojan') {
                array_push($proxy, \App\Protocols\Clash::buildTrojan($user['uuid'], $item));
                array_push($proxies, $item['name']);
            }
        }

        $config['proxies'] = array_merge($config['proxies'] ? $config['proxies'] : [], $proxy);
        foreach ($config['proxy-groups'] as $k => $v) {
            $config['proxy-groups'][$k]['proxies'] = array_merge($config['proxy-groups'][$k]['proxies'], $proxies);
        }
        $yamlContent = Yaml::dump($config);
        return response($yamlContent, 200)
            ->header('Content-Type', 'text/yaml');
    }

    public function getBootstrap(Request $request)
    {
        $user = $request->user;
        $userService = new UserService();
        if (!$userService->isAvailable($user)) {
            abort(403, '用户不可用');
        }

        $apiHosts = array_values(array_filter(array_map('trim', (array) config('v2board.app_api_domain_hosts', []))));
        $apiUrls = array_map(function ($host) {
            return sprintf('https://%s/api/v1/client/app', $host);
        }, $apiHosts);

        $payload = [
            'subscribe_url' => Helper::getAppSubscribeUrl($user['token']),
            'subscribe_path' => config('v2board.app_domain_subscribe_path', '/api/v1/client/custom_app/subscribe'),
            'replace_host' => config('v2board.app_domain_replace_host'),
            'api_domain_enable' => (int) config('v2board.app_api_domain_enable', 0),
            'api_domains' => $apiHosts,
            'api_urls' => $apiUrls,
            'app_config_url' => '/api/v1/client/app/getConfig',
            'app_version_url' => '/api/v1/client/app/getVersion',
        ];

        if ((int) config('v2board.app_api_domain_encrypt_enable', 0) === 1) {
            $encrypted = Helper::encryptAppPayload($apiUrls, (string) config('v2board.app_api_domain_encrypt_key', ''));
            if ($encrypted) {
                $payload['encrypted_api_urls'] = $encrypted;
            }
        }

        return response([
            'data' => $payload
        ]);
    }

    public function getVersion(Request $request)
    {
        $apiHosts = array_values(array_filter(array_map('trim', (array) config('v2board.app_api_domain_hosts', []))));
        $bootstrap = [
            'api_domain_enable' => (int) config('v2board.app_api_domain_enable', 0),
            'api_domains' => $apiHosts,
            'bootstrap_path' => '/api/v1/client/app/bootstrap',
        ];
        if ((int) config('v2board.app_api_domain_encrypt_enable', 0) === 1) {
            $encrypted = Helper::encryptAppPayload(array_map(function ($host) {
                return sprintf('https://%s/api/v1/client/app', $host);
            }, $apiHosts), (string) config('v2board.app_api_domain_encrypt_key', ''));
            if ($encrypted) {
                $bootstrap['encrypted_api_urls'] = $encrypted;
            }
        }

        if (strpos($request->header('user-agent'), 'tidalab/4.0.0') !== false
            || strpos($request->header('user-agent'), 'tunnelab/4.0.0') !== false
        ) {
            if (strpos($request->header('user-agent'), 'Win64') !== false) {
                return response([
                    'data' => [
                        'version' => config('v2board.windows_version'),
                        'download_url' => config('v2board.windows_download_url'),
                        'bootstrap' => $bootstrap
                    ]
                ]);
            } else {
                return response([
                    'data' => [
                        'version' => config('v2board.macos_version'),
                        'download_url' => config('v2board.macos_download_url'),
                        'bootstrap' => $bootstrap
                    ]
                ]);
            }
            return;
        }
        return response([
            'data' => [
                'windows_version' => config('v2board.windows_version'),
                'windows_download_url' => config('v2board.windows_download_url'),
                'macos_version' => config('v2board.macos_version'),
                'macos_download_url' => config('v2board.macos_download_url'),
                'android_version' => config('v2board.android_version'),
                'android_download_url' => config('v2board.android_download_url'),
                'bootstrap' => $bootstrap
            ]
        ]);
    }
}
