<?php

namespace App\Http\Controllers\V2\App;

use App\Models\Plan;
use App\Models\User;
use App\Services\AppClientProfileService;
use App\Services\UserService;
use App\Utils\Helper;

class UserController extends BaseController
{
    public function info()
    {
        $request = request();
        $clientProfile = app(AppClientProfileService::class)->resolve($request);
        $user = User::find($request->user['id']);
        if (!$user) {
            return $this->error('The user does not exist', 40401, 404);
        }

        $plan = $user->plan_id ? Plan::find($user->plan_id) : null;
        $userService = new UserService();
        $usedTraffic = (int) $user->u + (int) $user->d;
        $remainingTraffic = max((int) $user->transfer_enable - $usedTraffic, 0);

        return $this->success([
            'id' => $user->id,
            'email' => $user->email,
            'plan' => $plan ? [
                'id' => $plan->id,
                'name' => $plan->name,
            ] : null,
            'expired_at' => $user->expired_at,
            'transfer_enable' => (int) $user->transfer_enable,
            'used_traffic' => $usedTraffic,
            'remaining_traffic' => $remainingTraffic,
            'device_limit' => $user->device_limit,
            'balance' => $user->balance,
            'commission_balance' => $user->commission_balance,
            'is_available' => $userService->isAvailable($user),
            'subscribe_url' => Helper::getAppSubscribeUrl($user->token, $clientProfile),
            'status' => [
                'banned' => (bool) $user->banned,
                'is_available' => $userService->isAvailable($user),
            ],
        ]);
    }
}
