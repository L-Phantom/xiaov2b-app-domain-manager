<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AppDomainGroupSave extends FormRequest
{
    public function rules()
    {
        return [
            'id' => 'nullable|integer',
            'name' => 'required|string|max:128',
            'enable' => 'required|in:0,1',
            'sort' => 'nullable|integer',
            'domain' => 'required|string|max:255',
            'user_group_ids' => 'nullable|array',
            'user_group_ids.*' => 'integer',
            'plan_ids' => 'nullable|array',
            'plan_ids.*' => 'integer',
            'remark' => 'nullable|string|max:255',
        ];
    }

    public function messages()
    {
        return [
            'name.required' => '入口组名称不能为空',
            'domain.required' => '入口域名不能为空',
            'enable.in' => '启用状态格式不正确',
        ];
    }
}
