<?php

namespace App\Http\Resources;

use App\Models\Estate;

/** Compact `{ id, slug, name, location }` estate reference embedded in orders/positions. */
final class EstateReference
{
    public static function from(?Estate $estate): ?array
    {
        if ($estate === null) {
            return null;
        }

        return [
            'id' => $estate->id,
            'slug' => $estate->slug,
            'name' => $estate->name,
            'location' => [
                'address' => $estate->address,
                'city' => $estate->city,
                'state' => $estate->state,
                'latitude' => $estate->latitude,
                'longitude' => $estate->longitude,
            ],
        ];
    }
}
