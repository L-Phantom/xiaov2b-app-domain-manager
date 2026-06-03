<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AppDomainBindingSave extends FormRequest
{
    public function rules()
    {
        return [
            'id' => 'nullable|integer',
            'group_id' => 'required|integer',
            'enable' => 'required|in:0,1',
            'sort' => 'nullable|integer',
            'server_type' => 'required|string|max:32',
            'server_id' => 'required|integer',
            'port' => 'nullable|integer|min:1|max:65535',
            'remark' => 'nullable|string|max:255',
        ];
    }

    public function messages()
    {
        return [
            'group_id.required' => '入口组不能为空',
            'server_type.required' => '节点类型不能为空',
            'server_id.required' => '节点不能为空',
            'port.integer' => '入口端口格式不正确',
            'port.min' => '入口端口必须在 1-65535 之间',
            'port.max' => '入口端口必须在 1-65535 之间',
            'enable.in' => '启用状态格式不正确',
        ];
    }
}
