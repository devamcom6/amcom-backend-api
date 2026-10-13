<?php

namespace Database\Factories;

use App\Enums\Furnishing;
use App\Enums\ListingStatus;
use App\Enums\ListingType;
use App\Enums\PropertyCondition;
use App\Enums\PropertyType;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    public function definition(): array
    {
        $bedrooms = fake()->numberBetween(1, 5);
        $city = fake()->randomElement(['Lekki', 'Ikoyi', 'Victoria Island', 'Ikeja', 'Ajah', 'Port Harcourt']);
        $title = "{$bedrooms} Bed Apartment, {$city}";

        return [
            'reference' => 'LST-'.fake()->unique()->numberBetween(1000, 999999),
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'title' => $title,
            'listing_type' => ListingType::Sale,
            'property_type' => PropertyType::Apartment,
            'state' => $city === 'Port Harcourt' ? 'Rivers' : 'Lagos',
            'city' => $city,
            'address' => fake()->streetAddress().', '.$city,
            'size_sqm' => fake()->numberBetween(80, 400),
            'bedrooms' => $bedrooms,
            'bathrooms' => max(1, $bedrooms - 1),
            'price' => fake()->numberBetween(15, 300) * 1_000_000,
            'condition' => PropertyCondition::NewlyBuilt,
            'furnishing' => Furnishing::Furnished,
            'amenities' => ['parking', 'security'],
            'description' => fake()->paragraph(),
            'status' => ListingStatus::Published,
            'published_at' => now()->subDays(fake()->numberBetween(1, 60)),
        ];
    }

    public function status(ListingStatus $status): static
    {
        return $this->state(fn () => ['status' => $status, 'published_at' => $status === ListingStatus::Published ? now() : null]);
    }
}
