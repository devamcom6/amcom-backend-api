<?php

namespace App\Http\Resources;

use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Property — contract §8.1 */
class PropertySummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $cover = $this->photos->first();

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'listing_type' => $this->listing_type->value,
            'property_type' => $this->property_type->value,
            'price' => $this->price,
            'price_period' => $this->pricePeriod(),
            'bedrooms' => $this->bedrooms,
            'bathrooms' => $this->bathrooms,
            'size_sqm' => $this->size_sqm,
            'cover_image' => $cover ? new MediaResource($cover) : null,
        ];
    }
}
