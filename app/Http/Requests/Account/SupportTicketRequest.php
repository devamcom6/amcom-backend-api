<?php

namespace App\Http\Requests\Account;

use App\Enums\SupportCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupportTicketRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::enum(SupportCategory::class)],
            'subject' => ['required', 'string', 'min:3', 'max:150'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ];
    }
}
