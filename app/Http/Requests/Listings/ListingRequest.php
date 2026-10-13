<?php

namespace App\Http\Requests\Listings;

use App\Enums\Amenity;
use App\Enums\Furnishing;
use App\Enums\ListingType;
use App\Enums\PropertyCondition;
use App\Enums\PropertyType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create/update an owner listing (contract §9.2). */
class ListingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'listing_type' => ['required', Rule::enum(ListingType::class)],
            'property_type' => ['required', Rule::enum(PropertyType::class)],
            'state' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'address' => ['required', 'string', 'min:5', 'max:255'],
            'size_sqm' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'bedrooms' => ['nullable', 'integer', 'min:0', 'max:50'],
            'bathrooms' => ['nullable', 'integer', 'min:0', 'max:50'],
            'asking_price' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'condition' => ['required', Rule::enum(PropertyCondition::class)],
            'furnishing' => ['required', Rule::enum(Furnishing::class)],
            'amenities' => ['nullable', 'array', 'max:20'],
            'amenities.*' => ['string', Rule::enum(Amenity::class)],
            'description' => ['nullable', 'string', 'max:5000'],
            'title' => ['nullable', 'string', 'max:150'],
        ];
    }
}
