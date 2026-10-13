<?php

namespace App\Services;

use App\Models\Property;
use App\Services\Payments\PaystackService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Reference data for forms (contract §13.4). */
class LookupService
{
    public function __construct(private readonly PaystackService $paystack) {}

    public function all(): array
    {
        $static = Cache::remember('lookups.static', now()->addDay(), function () {
            $states = require database_path('data/nigeria_states.php');

            return [
                'countries' => config('amcom.lookups.countries'),
                'states' => collect($states)->map(fn (array $lgas, string $name) => ['name' => $name, 'lgas' => $lgas])->values()->all(),
                'id_types' => config('amcom.lookups.id_types'),
                'relationships' => config('amcom.lookups.relationships'),
            ];
        });

        return $static + [
            'banks' => $this->banks(),
            'property_locations' => $this->propertyLocations(),
        ];
    }

    /** @return list<array{code: string, name: string}> */
    public function banks(): array
    {
        if (! $this->paystack->isConfigured()) {
            return config('amcom.lookups.fallback_banks');
        }

        try {
            return $this->paystack->listBanks() ?: config('amcom.lookups.fallback_banks');
        } catch (Throwable $e) {
            Log::warning('lookups.banks_unavailable', ['error' => $e->getMessage()]);

            return config('amcom.lookups.fallback_banks');
        }
    }

    public function bankName(string $code): ?string
    {
        return collect($this->banks())->firstWhere('code', $code)['name'] ?? null;
    }

    /** @return list<string> */
    public function propertyLocations(): array
    {
        return Cache::remember('lookups.property_locations', now()->addHour(), fn () => Property::query()
            ->published()
            ->distinct()
            ->orderBy('city')
            ->pluck('city')
            ->filter()
            ->values()
            ->all());
    }
}
