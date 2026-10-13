<?php

namespace App\Http\Requests\Profile;

use App\Enums\IdType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VerifyIdentityRequest extends FormRequest
{
    public function rules(): array
    {
        $pattern = match ($this->input('id_type')) {
            'nin', 'bvn' => '/^\d{11}$/',
            default => '/^[A-Za-z0-9\-]{5,20}$/',
        };

        return [
            'id_type' => ['required', Rule::enum(IdType::class)],
            'id_number' => ['required', 'string', 'regex:'.$pattern],
        ];
    }

    public function messages(): array
    {
        return [
            'id_number.regex' => in_array($this->input('id_type'), ['nin', 'bvn'], true)
                ? 'The ID number must be 11 digits.'
                : 'The ID number format is invalid.',
        ];
    }
}
