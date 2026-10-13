<?php

namespace App\Http\Requests\Profile;

use App\Http\Requests\Rules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'first_name' => Rules::personName(false),
            'last_name' => Rules::personName(false),
            'phone' => Rules::phone(),
            'alt_phone' => Rules::phone(false),
            'country' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
        ];
    }
}
