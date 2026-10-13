<?php

namespace App\Http\Resources;

use App\Enums\ListingStatus;
use App\Enums\ListingType;
use App\Models\Property;
use App\Services\SettingsService;
use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Property — owner Listing (contract §9.5).
 * Activity counts come from `interested_parties_count` / `completed_viewings_count` (withCount).
 */
class ListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $live = in_array($this->status, [ListingStatus::Published, ListingStatus::Sold, ListingStatus::Rented], true);

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'title' => $this->title,
            'listing_type' => $this->listing_type->value,
            'property_type' => $this->property_type->value,
            'state' => $this->state,
            'city' => $this->city,
            'address' => $this->address,
            'size_sqm' => $this->size_sqm,
            'bedrooms' => $this->bedrooms,
            'bathrooms' => $this->bathrooms,
            'asking_price' => $this->price,
            'condition' => $this->condition?->value,
            'furnishing' => $this->furnishing?->value,
            'amenities' => $this->amenities ?? [],
            'description' => $this->description,
            'status' => $this->status->value,
            'photos' => MediaResource::collection($this->photos)->resolve($request),
            'documents' => DocumentResource::collection($this->documents)->resolve($request),
            'listed_at' => Format::ymd($this->published_at),
            'rejection_reason' => $this->rejection_reason,
            'financial_summary' => $live ? $this->financialSummary() : null,
            'activity' => $live ? [
                'status_label' => match ($this->status) {
                    ListingStatus::Sold => 'Sold',
                    ListingStatus::Rented => $this->listing_type === ListingType::Lease ? 'Leased' : 'Rented',
                    default => 'Available',
                },
                'listed_since' => Format::ymd($this->published_at),
                'interested_parties' => (int) ($this->resource->getAttributes()['interested_parties_count'] ?? 0),
                'completed_viewings' => (int) ($this->resource->getAttributes()['completed_viewings_count'] ?? 0),
            ] : null,
            'created_at' => Format::iso($this->created_at),
        ];
    }

    private function financialSummary(): array
    {
        $percent = app(SettingsService::class)->serviceFeePercent();
        $fee = (int) round($this->price * $percent / 100);

        return [
            'expected_sale_price' => $this->price,
            'service_fee_percent' => $percent,
            'service_fee' => $fee,
            'net_proceeds' => $this->price - $fee,
            'payment_status' => match ($this->status) {
                ListingStatus::Sold, ListingStatus::Rented => 'completed',
                default => $this->listing_type === ListingType::Sale ? 'pending_sale' : 'pending_rental',
            },
        ];
    }
}
