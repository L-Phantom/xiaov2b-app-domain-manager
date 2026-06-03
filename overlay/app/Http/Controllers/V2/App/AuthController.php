<?php

namespace App\Http\Controllers\V2\App;

use App\Http\Controllers\V1\Passport\AuthController as LegacyAuthController;
use App\Http\Controllers\V1\Passport\CommController as LegacyCommController;
use App\Http\Requests\Passport\AuthLogin;
use App\Http\Requests\Passport\AuthRegister;
use App\Http\Requests\Passport\CommSendEmailVerify;
use App\Models\User;
use App\Services\AuthService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AuthController extends BaseController
{
    public function register(AuthRegister $request, LegacyAuthController $legacyAuthController)
    {
        return $this->legacy(function () use ($request, $legacyAuthController) {
            return $legacyAuthController->register($request);
        });
    }

    public function login(AuthLogin $request, LegacyAuthController $legacyAuthController)
    {
        return $this->legacy(function () use ($request, $legacyAuthController) {
            return $legacyAuthController->login($request);
        });
    }

    public function sendEmailCode(CommSendEmailVerify $request, LegacyCommController $legacyCommController)
    {
        return $this->legacy(function () use ($request, $legacyCommController) {
            return $legacyCommController->sendEmailVerify($request);
        });
    }

    public function session(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            return $this->error('The user does not exist', 40401, 404);
        }

        return $this->success([
            'is_login' => true,
            'auth_data' => $request->input('auth_data') ?? $request->header('authorization'),
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'is_admin' => (bool) $user->is_admin,
                'is_staff' => (bool) $user->is_staff,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            return $this->error('The user does not exist', 40401, 404);
        }

        $authorization = $request->input('auth_data') ?? $request->header('authorization');
        $authService = new AuthService($user);
        if ($authorization) {
            try {
                $payload = (array) JWT::decode($authorization, new Key(config('app.key'), 'HS256'));
                if (!empty($payload['session'])) {
                    $authService->removeSession($payload['session']);
                }
                Cache::forget($authorization);
            } catch (\Throwable $e) {
                $authService->removeAllSession();
            }
        } else {
            $authService->removeAllSession();
        }

        return $this->success(true);
    }
}
