<?php

namespace App\Http\Resources\Admin;

use App\Models\Estate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Estate — contract §15.3. Expects withPlotStats(). */
class AdminEstateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $stats = $this->resource->getAttributes(); // withPlotStats() aggregates

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'city' => $this->city,
            'state' => $this->state,
            'total_plots' => (int) ($stats['total_plots'] ?? 0),
            'available_plots' => (int) ($stats['available_plots'] ?? 0),
            'reserved_plots' => (int) ($stats['reserved_plots'] ?? 0),
            'sold_plots' => (int) ($stats['sold_plots'] ?? 0),
            'checkout_mode' => $this->checkout_mode->value,
            'is_published' => $this->is_published,
        ];
    }
}
