<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/** Contract §15.13 — settings.view / settings.manage. Never contains secrets. */
class SettingsController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    public function show(): JsonResponse
    {
        return ApiResponse::success($this->publicShape($this->settings->all()));
    }

    public function update(UpdateSettingsRequest $request, AuditLogger $audit): JsonResponse
    {
        [$old, $new] = $this->settings->update($request->validated());

        $changedOld = [];
        $changedNew = [];
        foreach ($new as $group => $values) {
            foreach ($values as $key => $value) {
                if (($old[$group][$key] ?? null) !== $value) {
                    $changedOld["$group.$key"] = $old[$group][$key] ?? null;
                    $changedNew["$group.$key"] = $value;
                }
            }
        }

        if ($changedNew !== []) {
            $audit->log('settings.updated', null, $changedOld, $changedNew, 'updated system settings', subjectLabel: 'System settings');
        }

        return ApiResponse::success($this->publicShape($new), 'Settings saved.');
    }

    /** Contract shape: general, fees, capital_investment, notifications. */
    private function publicShape(array $settings): array
    {
        return [
            'general' => $settings['general'],
            'fees' => $settings['fees'],
            'capital_investment' => $settings['capital_investment'],
            'notifications' => $settings['notifications'],
        ];
    }
}
