<?php

namespace App\Http\Controllers\V2\App;

use App\Models\User;
use App\Services\AppClientProfileService;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;

class NodeController extends BaseController
{
    public function index()
    {
        $request = request();
        $user = User::find($request->user['id']);
        if (!$user) {
            return $this->error('The user does not exist', 40401, 404);
        }

        $userService = new UserService();
        if (!$userService->isAvailable($user)) {
            return $this->error('Subscription is unavailable', 40302, 403);
        }

        $serverService = new ServerService();
        $servers = $serverService->getAvailableAppServers($user);

        return $this->success([
            'items' => array_map(function ($server) {
                return $this->transformNode($server);
            }, $servers),
        ]);
    }

    public function manifest()
    {
        $request = request();
        $clientProfile = app(AppClientProfileService::class)->resolve($request);
        $user = User::find($request->user['id']);
        if (!$user) {
            return $this->error('The user does not exist', 40401, 404);
        }

        $userService = new UserService();
        if (!$userService->isAvailable($user)) {
            return $this->error('Subscription is unavailable', 40302, 403);
        }

        $serverService = new ServerService();
        $servers = $serverService->getAvailableAppServers($user);
        $hasAnyTls = collect($servers)->contains(function ($server) {
            return ($server['type'] ?? null) === 'anytls';
        });
        $protocolSummary = [];
        $coreSummary = [
            'mihomo' => 0,
            'sing-box' => 0,
        ];
        foreach ($servers as $server) {
            $type = (string) ($server['type'] ?? 'unknown');
            $protocolSummary[$type] = ($protocolSummary[$type] ?? 0) + 1;
            $requiredCore = $type === 'anytls' ? 'sing-box' : 'mihomo';
            $coreSummary[$requiredCore] = ($coreSummary[$requiredCore] ?? 0) + 1;
        }

        return $this->success([
            'subscribe_url' => Helper::getAppSubscribeUrl($user->token, $clientProfile),
            'profiles' => [
                [
                    'id' => 'default',
                    'label' => config('v2board.app_name', 'Default Subscription'),
                    'type' => 'clash',
                    'requires_core' => $hasAnyTls ? 'sing-box' : 'mihomo',
                ],
            ],
            'nodes' => array_map(function ($server) {
                return $this->transformNode($server);
            }, $servers),
            'has_anytls' => $hasAnyTls,
            'summary' => [
                'node_count' => count($servers),
                'protocols' => $protocolSummary,
                'cores' => $coreSummary,
            ],
        ]);
    }

    private function transformNode(array $server): array
    {
        return [
            'id' => $server['id'],
            'name' => $server['name'],
            'type' => $server['type'],
            'host' => $server['host'],
            'port' => $server['port'],
            'tags' => $server['tags'] ?? [],
            'is_online' => (int) ($server['is_online'] ?? 0),
            'supports_tun' => true,
            'supports_udp' => true,
            'requires_core' => ($server['type'] ?? '') === 'anytls' ? 'sing-box' : 'mihomo',
        ];
    }
}
