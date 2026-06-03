<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AppDomainRuleSave extends FormRequest
{
    public function rules()
    {
        return [
            'id' => 'nullable|integer',
            'name' => 'required|string|max:128',
            'enable' => 'required|in:0,1',
            'sort' => 'nullable|integer',
            'domain' => 'required|string|max:255',
            'port' => 'nullable|integer|min:1|max:65535',
            'user_group_ids' => 'nullable|array',
            'user_group_ids.*' => 'integer',
            'plan_ids' => 'nullable|array',
            'plan_ids.*' => 'integer',
            'server_types' => 'nullable|array',
            'server_types.*' => 'string',
            'server_ids' => 'nullable|array',
            'server_ids.*' => 'integer',
            'protocols' => 'nullable|array',
            'protocols.*' => 'string',
            'replace_node_host' => 'required|in:0,1',
            'replace_subscribe_host' => 'required|in:0,1',
            'remark' => 'nullable|string|max:255',
        ];
    }

    public function messages()
    {
        return [
            'name.required' => '规则名称不能为空',
            'domain.required' => '入口域名不能为空',
            'port.integer' => '入口端口格式不正确',
            'port.min' => '入口端口必须在 1-65535 之间',
            'port.max' => '入口端口必须在 1-65535 之间',
            'enable.in' => '启用状态格式不正确',
            'replace_node_host.in' => '节点入口覆盖状态格式不正确',
            'replace_subscribe_host.in' => '订阅入口覆盖状态格式不正确',
        ];
    }
}
