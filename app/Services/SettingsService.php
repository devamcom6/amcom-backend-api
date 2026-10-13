<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Runtime-editable, non-secret system settings (contract §15.13), stored per group
 * in the `settings` table and merged over defaults from config/amcom.php.
 */
class SettingsService
{
    private const CACHE_KEY = 'amcom.settings';

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $stored = Cache::rememberForever(self::CACHE_KEY, fn () => Setting::query()->pluck('value', 'key')->all());

        $settings = [];

        foreach (config('amcom.settings_defaults') as $group => $defaults) {
            $settings[$group] = array_replace($defaults, (array) ($stored[$group] ?? []));
        }

        return $settings;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->all(), $key, $default);
    }

    /**
     * @param  array<string, array<string, mixed>>  $groups  partial: only the provided keys are changed
     * @return array{0: array, 1: array} [old, new]
     */
    public function update(array $groups): array
    {
        $old = $this->all();

        DB::transaction(function () use ($groups, $old) {
            foreach ($groups as $group => $values) {
                if (! array_key_exists($group, $old)) {
                    continue;
                }

                Setting::query()->updateOrCreate(
                    ['key' => $group],
                    ['value' => array_replace($old[$group], array_intersect_key($values, $old[$group]))],
                );
            }
        });

        $this->flush();

        return [$old, $this->all()];
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function maintenanceMode(): bool
    {
        return (bool) $this->get('general.maintenance_mode', false);
    }

    public function listingFee(): int
    {
        return (int) $this->get('fees.listing_fee');
    }

    public function serviceFeePercent(): float
    {
        return (float) $this->get('fees.sale_service_fee_percent');
    }

    public function adminAlertEmail(): ?string
    {
        return $this->get('notifications.admin_alert_email') ?: null;
    }

    /** @return array<string, mixed> */
    public function capitalPlan(): array
    {
        return $this->get('capital_investment');
    }
}
