<?php

namespace App\Http\Requests;

use App\Enums\PayableType;
use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Note: there is deliberately no `amount` field — the amount is always computed server-side. */
class InitializePaymentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'payable_type' => ['required', Rule::enum(PayableType::class)],
            'payable_id' => ['required', 'integer', 'min:1'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'callback_url' => ['nullable', 'string', 'url:https,http', 'max:500'],
        ];
    }
}
