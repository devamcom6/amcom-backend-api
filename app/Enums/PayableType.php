<?php

namespace App\Enums;

enum PayableType: string
{
    use HasValues;

    case BuybackOrder = 'buyback_order';
    case CapitalInvestment = 'capital_investment';
    case ListingFee = 'listing_fee';
    case HoldingPayment = 'holding_payment';

    public function label(): string
    {
        return match ($this) {
            self::BuybackOrder => 'Buyback purchase',
            self::CapitalInvestment => 'Capital investment',
            self::ListingFee => 'Listing registration fee',
            self::HoldingPayment => 'Rent / lease payment',
        };
    }
}
