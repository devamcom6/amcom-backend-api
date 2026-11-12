<?php

namespace App\Http\Requests\Buyback;

use App\Http\Requests\Rules;
use Illuminate\Foundation\Http\FormRequest;

class CreateBuybackOrderRequest extends FormRequest
{
    public function rules(): array
    {
        return Rules::referrer() + [
            'estate_id' => ['required', 'integer'],
            'plot_ids' => ['required', 'array', 'min:1', 'max:20'],
            'plot_ids.*' => ['required', 'integer', 'distinct'],
            'tenure_months' => ['required', 'integer', 'min:1', 'max:120'],
        ];
    }
}
