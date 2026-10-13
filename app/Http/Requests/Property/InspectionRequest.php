<?php

namespace App\Http\Requests\Property;

use App\Enums\InspectionType;
use App\Http\Requests\Rules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InspectionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(InspectionType::class)],
            'attendee' => ['required', 'in:self,someone_else'],
            'full_name' => ['required', 'string', 'min:3', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => Rules::phone(),
            'date' => ['required', 'date_format:Y-m-d', 'after:today'],
            'time' => ['required', 'date_format:H:i', Rule::in(config('amcom.inspections.times'))],
            'message' => ['nullable', 'string', 'max:2000'],
            'website' => ['prohibited'], // honeypot
        ];
    }

    public function messages(): array
    {
        return ['time.in' => 'Please choose one of the available time slots.'];
    }
}
