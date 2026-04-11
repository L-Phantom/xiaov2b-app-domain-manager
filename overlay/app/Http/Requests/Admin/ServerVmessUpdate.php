<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ServerVmessUpdate extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */

    public function rules()
    {
        return [
            'show' => 'in:0,1',
            'app_show' => 'in:0,1'
        ];
    }

    public function messages()
    {
        return [
            'show.in' => '显示状态格式不正确',
            'app_show.in' => 'App可见状态格式不正确'
        ];
    }
}
