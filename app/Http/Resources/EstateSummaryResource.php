<?php

namespace App\Http\Resources;

use App\Models\Estate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Estate — contract §6.1. Expects withPlotStats() + tenures + gallery loaded. */
class EstateSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $tenures = $this->tenures;
        $stats = $this->resource->getAttributes(); // withPlotStats() aggregates
        $cover = $this->gallery->first();

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'location' => $this->location(),
            'size_sqm' => $this->size_sqm,
            'tenure_min_months' => $tenures->min('months'),
            'tenure_max_months' => $tenures->max('months'),
            'roi_min_percent' => $tenures->min('roi_percent'),
            'roi_max_percent' => $tenures->max('roi_percent'),
            'price_from' => isset($stats['price_from']) ? (int) $stats['price_from'] : null,
            'available_plots' => (int) ($stats['available_plots'] ?? 0),
            'cover_image' => $cover ? new MediaResource($cover) : null,
        ];
    }

    protected function location(): array
    {
        return [
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ];
    }
}
