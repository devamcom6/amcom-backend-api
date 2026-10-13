<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

class PayoutAccountRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'bank_code' => ['required', 'string', 'max:20', 'regex:/^[0-9A-Za-z]+$/'],
            'account_number' => ['required', 'string', 'regex:/^\d{10}$/'],
        ];
    }

    public function messages(): array
    {
        return ['account_number.regex' => 'Account number must be 10 digits.'];
    }
}
