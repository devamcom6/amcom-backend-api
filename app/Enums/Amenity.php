<?php

namespace App\Enums;

enum Amenity: string
{
    use HasValues;

    case Parking = 'parking';
    case Security = 'security';
    case Generator = 'generator';
    case Borehole = 'borehole';
    case SwimmingPool = 'swimming_pool';
    case Gym = 'gym';
    case Cctv = 'cctv';
    case FenceGate = 'fence_gate';
    case Serviced = 'serviced';
}
