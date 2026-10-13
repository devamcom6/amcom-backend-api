<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class BulkUserActionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'action' => ['required', 'in:suspend,activate,resend_verification,export'],
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct'],
        ];
    }
}
