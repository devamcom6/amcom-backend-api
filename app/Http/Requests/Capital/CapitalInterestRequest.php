<?php

namespace App\Http\Requests\Capital;

use App\Http\Requests\Rules;
use Illuminate\Foundation\Http\FormRequest;

class CapitalInterestRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1'],
            'tenure_months' => ['required', 'integer', 'min:1', 'max:120'],
            'full_name' => ['required', 'string', 'min:3', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => Rules::phone(),
            'alt_phone' => Rules::phone(false),
            'note' => ['nullable', 'string', 'max:2000'],
            'website' => ['prohibited'], // honeypot
        ];
    }
}
