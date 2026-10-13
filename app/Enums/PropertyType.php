<?php

namespace App\Enums;

enum PropertyType: string
{
    use HasValues;

    case Apartment = 'apartment';
    case Duplex = 'duplex';
    case House = 'house';
    case Land = 'land';
    case Commercial = 'commercial';
    case Office = 'office';
    case Warehouse = 'warehouse';
    case Terrace = 'terrace';
    case RealEstate = 'real_estate';
    case Other = 'other';
}
