<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Services\AppDomainService;
use Illuminate\Http\Request;

class AppDomainController extends Controller
{
    public function fetch()
    {
        return response([
            'data' => (new AppDomainService())->getAdminConfig()
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

        if (!(new AppDomainService())->saveConfig($data)) {
            abort(500, 'App域名配置保存失败');
        }

        return response([
            'data' => true
        ]);
    }
}
