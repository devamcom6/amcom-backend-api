<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ContactRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'min:2', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => Rules::phone(false),
            'subject' => ['required', 'string', 'min:2', 'max:150'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'website' => ['prohibited'], // honeypot: bots fill every field
        ];
    }
}
