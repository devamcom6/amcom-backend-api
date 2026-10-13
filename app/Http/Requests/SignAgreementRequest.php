<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SignAgreementRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'accepted' => ['required', 'accepted'],
            // At least two words when present (e-signature).
            'typed_signature_name' => ['nullable', 'string', 'max:150', 'regex:/^\S+(\s+\S+)+$/u'],
        ];
    }

    public function messages(): array
    {
        return [
            'accepted.accepted' => 'You must accept the agreement to continue.',
            'accepted.required' => 'You must accept the agreement to continue.',
            'typed_signature_name.regex' => 'Type your full name (first and last name) to sign.',
        ];
    }
}
