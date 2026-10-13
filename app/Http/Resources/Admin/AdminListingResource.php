<?php

namespace App\Http\Resources\Admin;

use App\Models\Property;
use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Property — admin verification queue item (contract §15.4). Expects photos_count. */
class AdminListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'title' => $this->title,
            'owner' => $this->owner ? ['id' => $this->owner->id, 'name' => $this->owner->displayName(), 'email' => $this->owner->email] : null,
            'listing_type' => $this->listing_type->value,
            'property_type' => $this->property_type->value,
            'city' => $this->city,
            'state' => $this->state,
            'asking_price' => $this->price,
            'status' => $this->status->value,
            'photos_count' => (int) ($this->resource->getAttributes()['photos_count'] ?? 0),
            'fee_paid' => $this->fee_paid_at !== null,
            'rejection_reason' => $this->rejection_reason,
            'submitted_at' => Format::iso($this->submitted_at),
        ];
    }
}
