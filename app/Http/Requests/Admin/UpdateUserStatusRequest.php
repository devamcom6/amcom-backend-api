<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserStatusRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', 'in:active,suspended'],
            'reason' => ['required_if:status,suspended', 'nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return ['reason.required_if' => 'Please give a reason for suspending this account.'];
    }
}
