<?php

namespace App\Http\Resources;

use App\Models\PropertyHolding;
use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PropertyHolding — list item (contract §10). */
class HoldingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $property = $this->property;
        $cover = $property?->photos->first();

        return [
            'id' => $this->id,
            'holding_type' => $this->holding_type->value,
            'status' => $this->status->value,
            'status_label' => $this->statusLabel(),
            'property' => $property ? [
                'id' => $property->id,
                'title' => $property->title,
                'address' => $property->address,
                'size_sqm' => $property->size_sqm,
                'cover_image' => $cover ? new MediaResource($cover) : null,
            ] : null,
            'purchase_price' => $this->purchase_price,
            'acquired_on' => Format::ymd($this->acquired_on),
            'title_document' => $this->title_document,
            'ownership_status' => $this->ownership_status,
            'rate' => $this->rate,
            'rate_period' => $this->rate_period,
            'period_start' => Format::ymd($this->period_start),
            'period_end' => Format::ymd($this->period_end),
            'renewal_date' => Format::ymd($this->renewal_date),
            'next_payment_date' => Format::ymd($this->next_payment_date),
            'term_label' => $this->term_label,
        ];
    }
}
