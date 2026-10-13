<?php

namespace App\Enums;

enum Product: string
{
    use HasValues;

    case Buyback = 'buyback';
    case CapitalInvestment = 'capital_investment';
}
