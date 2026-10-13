<?php

namespace App\Enums;

enum HoldingType: string
{
    use HasValues;

    case OutrightPurchase = 'outright_purchase';
    case Lease = 'lease';
    case Rent = 'rent';
}
