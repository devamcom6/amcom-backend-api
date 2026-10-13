<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Rules;
use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'reset_token' => ['required', 'string', 'max:128'],
            'password' => Rules::password(),
        ];
    }
}
