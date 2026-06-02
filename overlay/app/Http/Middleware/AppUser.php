<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AuthService;
use Closure;

class AppUser
{
    public function handle($request, Closure $next)
    {
        $authorization = $request->input('auth_data') ?? $request->header('authorization');
        if (is_string($authorization)) {
            $authorization = trim($authorization);
            if (stripos($authorization, 'Bearer ') === 0) {
                $authorization = trim(substr($authorization, 7));
            }
        }
        if (!$authorization) {
            return response()->json([
                'code' => 40101,
                'message' => '未登录或登陆已过期',
                'data' => null,
            ], 401);
        }

        $user = AuthService::decryptAuthData($authorization);
        if (!$user) {
            return response()->json([
                'code' => 40102,
                'message' => '未登录或登陆已过期',
                'data' => null,
            ], 401);
        }

        $resolvedUser = null;
        if ($user instanceof User) {
            $resolvedUser = $user;
        } elseif (is_array($user) && isset($user['id'])) {
            $resolvedUser = User::find($user['id']);
        }
        if (!$resolvedUser) {
            return response()->json([
                'code' => 40103,
                'message' => '用户不存在或已失效',
                'data' => null,
            ], 401);
        }

        $request->merge([
            'user' => $resolvedUser,
            'auth_data' => $authorization,
        ]);

        return $next($request);
    }
}
