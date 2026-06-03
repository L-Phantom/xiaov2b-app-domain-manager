<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AppDomainBindingSave;
use App\Http\Requests\Admin\AppDomainGroupSave;
use App\Http\Requests\Admin\AppDomainRuleSave;
use App\Http\Requests\Admin\AppDomainRuleSort;
use App\Services\AppDomainService;
use Illuminate\Http\Request;

class AppDomainController extends Controller
{
    public function fetch()
    {
        return $this->config();
    }

    public function config()
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
            'app_domain_rule_enable' => 'nullable|in:0,1',
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

    public function rules()
    {
        return response([
            'data' => (new AppDomainService())->getRules()
        ]);
    }

    public function groups()
    {
        return response([
            'data' => (new AppDomainService())->getGroups()
        ]);
    }

    public function saveGroup(AppDomainGroupSave $request)
    {
        return response([
            'data' => (new AppDomainService())->saveGroup($request->validated())
        ]);
    }

    public function dropGroup(Request $request)
    {
        $id = (int) $request->input('id');
        if (!$id) {
            abort(500, '入口组ID不能为空');
        }

        return response([
            'data' => (new AppDomainService())->dropGroup($id)
        ]);
    }

    public function saveBinding(AppDomainBindingSave $request)
    {
        return response([
            'data' => (new AppDomainService())->saveBinding($request->validated())
        ]);
    }

    public function dropBinding(Request $request)
    {
        $id = (int) $request->input('id');
        if (!$id) {
            abort(500, '入口绑定ID不能为空');
        }

        return response([
            'data' => (new AppDomainService())->dropBinding($id)
        ]);
    }

    public function saveRule(AppDomainRuleSave $request)
    {
        return response([
            'data' => (new AppDomainService())->saveRule($request->validated())
        ]);
    }

    public function dropRule(Request $request)
    {
        $id = (int) $request->input('id');
        if (!$id) {
            abort(500, '规则ID不能为空');
        }

        return response([
            'data' => (new AppDomainService())->dropRule($id)
        ]);
    }

    public function sortRule(AppDomainRuleSort $request)
    {
        return response([
            'data' => (new AppDomainService())->sortRules($request->input('rule_ids'))
        ]);
    }

    public function options()
    {
        return response([
            'data' => (new AppDomainService())->getOptions()
        ]);
    }
}
