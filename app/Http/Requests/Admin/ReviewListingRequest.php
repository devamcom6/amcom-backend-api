<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ReviewListingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['required_if:decision,reject', 'nullable', 'string', 'min:5', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return ['reason.required_if' => 'Please tell the owner what needs to change.'];
    }
}
