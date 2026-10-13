<?php

namespace App\Enums;

enum ListingType: string
{
    use HasValues;

    case Sale = 'sale';
    case Rent = 'rent';
    case Lease = 'lease';
}
