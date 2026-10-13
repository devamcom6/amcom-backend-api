<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Contract §8.2: PropertySummary + description, features, gallery, coordinates, condition, furnishing. */
class PropertyDetailResource extends PropertySummaryResource
{
    public function toArray(Request $request): array
    {
        $features = $this->features ?: collect($this->amenities ?? [])->map(fn ($a) => Str::headline($a))->all();

        return parent::toArray($request) + [
            'description' => $this->description,
            'features' => array_values($features),
            'gallery' => MediaResource::collection($this->photos)->resolve($request),
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'location_label' => $this->locationLabel(),
            'condition' => $this->condition?->value,
            'furnishing' => $this->furnishing?->value,
        ];
    }
}
