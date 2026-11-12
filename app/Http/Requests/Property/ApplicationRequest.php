<?php

namespace App\Http\Requests\Property;

use App\Http\Requests\Rules;
use Illuminate\Foundation\Http\FormRequest;

class ApplicationRequest extends FormRequest
{
    public function rules(): array
    {
        return Rules::referrer() + [
            'full_name' => ['required', 'string', 'min:3', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => Rules::phone(),
            'message' => ['required', 'string', 'min:5', 'max:3000'],
            'accept_terms' => ['required', 'accepted'],
            'website' => ['prohibited'], // honeypot
        ];
    }

    public function messages(): array
    {
        return [
            'accept_terms.required' => 'Please confirm you have read and understood the Terms and Conditions.',
            'accept_terms.accepted' => 'Please confirm you have read and understood the Terms and Conditions.',
        ];
    }
}
