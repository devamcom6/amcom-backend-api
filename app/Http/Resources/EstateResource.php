<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** Contract §6.2: EstateSummary + description, gallery, tenures, checkout_mode, max_plots_per_order. */
class EstateResource extends EstateSummaryResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'description' => $this->description,
            'gallery' => MediaResource::collection($this->gallery)->resolve($request),
            'tenures' => $this->tenures->map(fn ($t) => ['months' => $t->months, 'roi_percent' => $t->roi_percent])->values()->all(),
            'checkout_mode' => $this->checkout_mode->value,
            'max_plots_per_order' => $this->max_plots_per_order,
        ];
    }
}
