<?php

namespace App\Http\Requests\Buyback;

use App\Http\Requests\Rules;
use Illuminate\Foundation\Http\FormRequest;

class BuybackInterestRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'estate_id' => ['required', 'integer'],
            'plot_ids' => ['required', 'array', 'min:1', 'max:20'],
            'plot_ids.*' => ['required', 'integer', 'distinct'],
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
