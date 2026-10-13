<?php

namespace App\Http\Requests\Admin;

use App\Enums\FaqProduct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FaqRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'product' => ['required', Rule::enum(FaqProduct::class)],
            'question' => ['required', 'string', 'min:5', 'max:500'],
            'answer' => ['required', 'string', 'min:5', 'max:5000'],
            'is_published' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ];
    }
}
