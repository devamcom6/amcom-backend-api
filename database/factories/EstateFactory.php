<?php

namespace Database\Factories;

use App\Enums\CheckoutMode;
use App\Models\Estate;
use App\Models\Plot;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Estate>
 */
class EstateFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->randomElement(['Palm', 'Cedar', 'Oak', 'Maple', 'Willow', 'Pine', 'Acacia', 'Iroko']).' '.fake()->randomElement(['Gardens', 'Heights', 'Court', 'Estate']).' '.fake()->unique()->numberBetween(1, 9999);

        return [
            'slug' => Str::slug($name),
            'name' => $name,
            'description' => fake()->paragraph(),
            'address' => fake()->streetAddress(),
            'city' => 'Etche',
            'state' => 'Rivers State',
            'latitude' => 4.9917,
            'longitude' => 7.0544,
            'size_sqm' => 465,
            'checkout_mode' => CheckoutMode::Direct,
            'max_plots_per_order' => 5,
            'is_published' => true,
        ];
    }

    public function interestOnly(): static
    {
        return $this->state(fn () => ['checkout_mode' => CheckoutMode::Interest]);
    }

    /** Adds the standard tenure ladder and N available plots. */
    public function withInventory(int $plots = 5, int $price = 2_000_000): static
    {
        return $this->afterCreating(function (Estate $estate) use ($plots, $price) {
            foreach ([[12, 18], [18, 28], [24, 42], [36, 65]] as [$months, $roi]) {
                $estate->tenures()->create(['months' => $months, 'roi_percent' => $roi]);
            }

            $prefix = strtoupper(substr($estate->slug, 0, 1));
            for ($i = 1; $i <= $plots; $i++) {
                $estate->plots()->save(new Plot([
                    'code' => sprintf('%s%03d', $prefix, $i),
                    'size_sqm' => 465,
                    'price' => $price,
                    'status' => 'available',
                ]));
            }
        });
    }
}
