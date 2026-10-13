<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Rules;
use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:128'],
            'password' => Rules::password(),
        ];
    }
}
