<?php

namespace App\Http\Requests\Property;

use App\Http\Requests\Rules;
use Illuminate\Foundation\Http\FormRequest;

class ApplicationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'min:3', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => Rules::phone(),
            'message' => ['required', 'string', 'min:5', 'max:3000'],
            'website' => ['prohibited'], // honeypot
        ];
    }
}
