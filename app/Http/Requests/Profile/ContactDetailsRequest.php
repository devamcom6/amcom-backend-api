<?php

namespace App\Http\Requests\Profile;

use App\Http\Requests\Rules;
use Illuminate\Foundation\Http\FormRequest;

class ContactDetailsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'country' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['required', 'string', 'min:5', 'max:255'],
            'phone' => Rules::phone(),
            'alt_phone' => array_merge(Rules::phone(false), ['different:phone']),
        ];
    }
}
