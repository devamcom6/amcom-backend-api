<?php

namespace App\Http\Requests\Admin;

use App\Services\SettingsService;
use Illuminate\Foundation\Http\FormRequest;

/** System settings (contract §15.13). Errors use dot keys, e.g. `fees.listing_fee`. */
class UpdateSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'general' => ['sometimes', 'array'],
            'general.support_email' => ['sometimes', 'email', 'max:255'],
            'general.support_phone' => ['sometimes', 'string', 'max:30'],
            'general.office_address' => ['sometimes', 'string', 'max:255'],
            'general.maintenance_mode' => ['sometimes', 'boolean'],

            'fees' => ['sometimes', 'array'],
            'fees.listing_fee' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'fees.sale_service_fee_percent' => ['sometimes', 'numeric', 'min:0', 'max:50'],

            'capital_investment' => ['sometimes', 'array'],
            'capital_investment.min_amount' => ['sometimes', 'integer', 'min:1000'],
            'capital_investment.max_self_serve_amount' => ['sometimes', 'integer', 'gt:capital_investment.min_amount'],
            'capital_investment.checkout_mode' => ['sometimes', 'in:direct,interest'],
            'capital_investment.tenures' => ['sometimes', 'array', 'min:1', 'max:12'],
            'capital_investment.tenures.*.months' => ['required_with:capital_investment.tenures', 'integer', 'min:1', 'max:120', 'distinct'],
            'capital_investment.tenures.*.roi_percent' => ['required_with:capital_investment.tenures', 'numeric', 'min:0', 'max:500'],
            'capital_investment.pricing_examples' => ['sometimes', 'array', 'max:10'],
            'capital_investment.pricing_examples.*' => ['integer', 'min:1'],

            'notifications' => ['sometimes', 'array'],
            'notifications.admin_alert_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'notifications.facility_manager_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'notifications.send_maturity_reminders_days_before' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // gt: needs both values; compare against the stored minimum when only the max is sent.
        $capital = $this->input('capital_investment');

        if (is_array($capital) && isset($capital['max_self_serve_amount']) && ! isset($capital['min_amount'])) {
            $capital['min_amount'] = app(SettingsService::class)->get('capital_investment.min_amount');
            $this->merge(['capital_investment' => $capital]);
        }
    }
}
