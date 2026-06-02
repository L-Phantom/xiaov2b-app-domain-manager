<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AppDomainRuleSort extends FormRequest
{
    public function rules()
    {
        return [
            'rule_ids' => 'required|array',
            'rule_ids.*' => 'integer',
        ];
    }

    public function messages()
    {
        return [
            'rule_ids.required' => '规则排序不能为空',
            'rule_ids.array' => '规则排序格式不正确',
        ];
    }
}
