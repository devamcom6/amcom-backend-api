<?php

namespace App\Http\Requests\Profile;

use App\Http\Requests\Rules;
use Illuminate\Foundation\Http\FormRequest;

class PersonalInfoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'state_of_origin' => ['required', 'string', 'max:100'],
            'lga' => ['required', 'string', 'max:100'],
            // Only sent while identity is not verified (locked otherwise).
            'first_name' => Rules::personName(false),
            'last_name' => Rules::personName(false),
            'date_of_birth' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before:-18 years'],
            'gender' => ['sometimes', 'nullable', 'string', 'in:male,female,other'],
            'nationality' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return ['date_of_birth.before' => 'You must be at least 18 years old.'];
    }
}
