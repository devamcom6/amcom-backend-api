<?php

namespace App\Enums;

enum FaqProduct: string
{
    use HasValues;

    case Buyback = 'buyback';
    case CapitalInvestment = 'capital_investment';
    case PropertyManagement = 'property_management';
    case General = 'general';
}
