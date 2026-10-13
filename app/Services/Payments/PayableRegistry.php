<?php

namespace App\Services\Payments;

use App\Enums\PayableType;
use App\Enums\Product;
use App\Services\Payments\Payables\HoldingPayable;
use App\Services\Payments\Payables\InvestmentPayable;
use App\Services\Payments\Payables\ListingFeePayable;
use App\Services\Payments\Payables\Payable;
use Illuminate\Contracts\Container\Container;

class PayableRegistry
{
    public function __construct(private readonly Container $container) {}

    public function for(PayableType $type): Payable
    {
        return match ($type) {
            PayableType::BuybackOrder => $this->container->make(InvestmentPayable::class, ['product' => Product::Buyback]),
            PayableType::CapitalInvestment => $this->container->make(InvestmentPayable::class, ['product' => Product::CapitalInvestment]),
            PayableType::ListingFee => $this->container->make(ListingFeePayable::class),
            PayableType::HoldingPayment => $this->container->make(HoldingPayable::class),
        };
    }
}
