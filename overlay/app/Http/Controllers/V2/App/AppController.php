<?php

namespace App\Http\Controllers\V2\App;

use App\Http\Controllers\V1\Client\AppController as LegacyAppController;
use App\Models\ServerAnytls;
use App\Services\AppClientProfileService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class AppController extends BaseController
{
    public function bootstrap(Request $request)
    {
        $clientProfileService = app(AppClientProfileService::class);
        $clientProfile = $clientProfileService->resolve($request);
        $apiBaseUrls = $clientProfileService->buildApiUrls($clientProfile);

        return $this->success([
            'app_name' => $clientProfile['app_name'],
            'app_url' => $clientProfile['app_url'],
            'logo' => $clientProfile['logo'],
            'tos_url' => $clientProfile['tos_url'],
            'api_base_urls' => $apiBaseUrls,
            'client_config_url' => '/api/v2/app/client/config',
            'version_url' => '/api/v2/app/client/version',
            'notice_popup_enabled' => true,
            'node_auto_sync_enabled' => true,
            'order_enabled' => true,
            'tun_enabled' => true,
            'capability_url' => '/api/v2/app/capabilities',
            'notice_url' => '/api/v2/app/notices',
            'plan_url' => '/api/v2/app/plans',
            'disaster_recovery_url' => '/api/v2/app/disaster-recovery',
            'auth' => [
                'login_url' => '/api/v2/app/auth/login',
                'register_url' => '/api/v2/app/auth/register',
                'session_url' => '/api/v2/app/auth/session',
            ],
            'minimum_versions' => $clientProfile['minimum_versions'],
            'resolved_client' => $clientProfileService->buildDebugPayload($clientProfile, $request),
        ]);
    }

    public function capabilities()
    {
        $hasAnyTls = ServerAnytls::query()->exists();

        return $this->success([
            'feature_flags' => [
                'enable_order' => true,
                'enable_notice_popup' => true,
                'enable_tun' => true,
                'enable_auto_sync' => true,
                'enable_singbox_core' => $hasAnyTls,
                'enable_anytls' => $hasAnyTls,
            ],
            'cores' => [
                [
                    'name' => 'mihomo',
                    'protocols' => ['vmess', 'vless', 'trojan', 'ss', 'tuic', 'hysteria'],
                ],
                [
                    'name' => 'sing-box',
                    'protocols' => ['vmess', 'vless', 'trojan', 'ss', 'tuic', 'hysteria', 'anytls'],
                ],
            ],
            'platform_matrix' => [
                'android' => ['tun' => true, 'system_proxy' => false],
                'windows' => ['tun' => true, 'system_proxy' => true],
                'macos' => ['tun' => true, 'system_proxy' => true],
            ],
        ]);
    }

    public function version(Request $request, LegacyAppController $legacyAppController)
    {
        return $this->legacy(function () use ($request, $legacyAppController) {
            return $legacyAppController->getVersion($request);
        });
    }

    public function clientConfig(Request $request)
    {
        $clientProfileService = app(AppClientProfileService::class);
        $clientProfile = $clientProfileService->resolve($request);
        $user = $request->user;
        $userService = new UserService();
        $isAvailable = $user ? $userService->isAvailable($user) : false;
        $apiUrls = $clientProfileService->buildApiUrls($clientProfile, 'v1');

        return $this->success([
            'subscribe_url' => $isAvailable ? Helper::getAppSubscribeUrl($user['token'], $clientProfile) : '',
            'subscribe_path' => (string) $clientProfile['subscribe_path'],
            'subscribe_signature' => [
                'enabled' => (int) $clientProfile['subscribe_sign_enable'] === 1,
                'require_timestamp' => (int) $clientProfile['subscribe_sign_require_timestamp'] === 1,
                'max_skew_seconds' => (int) $clientProfile['subscribe_sign_max_skew_seconds'],
            ],
            'replace_host' => (string) $clientProfile['replace_host'],
            'api_domains' => array_values((array) $clientProfile['api_hosts']),
            'api_urls' => array_values((array) $apiUrls),
            'resolved_client' => $clientProfileService->buildDebugPayload($clientProfile, $request),
            'app_config_url' => '/api/v1/client/app/getConfig',
            'app_version_url' => '/api/v1/client/app/getVersion',
            'default_core' => 'mihomo',
            'default_mode' => 'rule',
            'capability_url' => '/api/v2/app/capabilities',
            'user_info_url' => '/api/v2/app/user/info',
            'node_manifest_url' => '/api/v2/app/nodes/manifest',
            'node_list_url' => '/api/v2/app/nodes/list',
            'order_url' => '/api/v2/app/orders',
            'payment_methods_url' => '/api/v2/app/orders/payment-methods',
            'notices_url' => '/api/v2/app/notices',
            'plans_url' => '/api/v2/app/plans',
            'disaster_recovery_url' => '/api/v2/app/disaster-recovery',
            'is_subscription_available' => $isAvailable,
        ]);
    }

    public function disasterRecovery()
    {
        $request = request();
        $clientProfileService = app(AppClientProfileService::class);
        $clientProfile = $clientProfileService->resolve($request);
        $apiHosts = $clientProfile['api_hosts'];
        $subscribeHost = trim((string) $clientProfile['subscribe_host']);
        $subscribeHosts = array_values(array_filter(array_unique(array_merge(
            $subscribeHost ? [$subscribeHost] : [],
            $apiHosts
        ))));

        return $this->success([
            'api_domains' => $apiHosts,
            'subscribe_domains' => $subscribeHosts,
            'replace_host' => trim((string) $clientProfile['replace_host']),
            'subscribe_path' => $clientProfile['subscribe_path'],
            'subscribe_signature' => [
                'enabled' => (int) $clientProfile['subscribe_sign_enable'] === 1,
                'require_timestamp' => (int) $clientProfile['subscribe_sign_require_timestamp'] === 1,
                'max_skew_seconds' => (int) $clientProfile['subscribe_sign_max_skew_seconds'],
            ],
            'healthcheck_urls' => $clientProfileService->buildApiUrls($clientProfile),
            'updated_at' => time(),
            'resolved_client' => $clientProfileService->buildDebugPayload($clientProfile, $request),
        ]);
    }

    public function clientDebug(Request $request)
    {
        $clientProfileService = app(AppClientProfileService::class);
        $clientProfile = $clientProfileService->resolve($request);

        return $this->success([
            'resolved_client' => $clientProfileService->buildDebugPayload($clientProfile, $request),
            'version_url' => '/api/v2/app/client/version',
            'bootstrap_url' => '/api/v2/app/bootstrap',
            'config_url' => '/api/v2/app/client/config',
        ]);
    }

    public function diagnosticsReport(Request $request)
    {
        return $this->success([
            'accepted' => true,
            'received_at' => time(),
            'platform' => $request->input('platform'),
            'app_version' => $request->input('app_version'),
            'core' => $request->input('core'),
        ]);
    }
}
