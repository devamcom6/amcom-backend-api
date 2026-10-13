<?php

namespace App\Http\Requests\Profile;

use App\Http\Requests\Rules;
use Illuminate\Foundation\Http\FormRequest;

class NextOfKinRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'min:3', 'max:150'],
            'relationship' => ['required', 'string', 'max:50'],
            'phone' => Rules::phone(),
            'email' => ['nullable', 'string', 'email', 'max:255'],
        ];
    }
}
