<?php

namespace App\Services\Investments;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Authoritative investment maths. The ROI percentage is the total return for the chosen tenure
 * (not annualised): projected_return = principal × (1 + roi% / 100).
 * See handoff §8 #5 — confirm with Finance; only this class needs to change.
 */
class QuoteCalculator
{
    /**
     * @return array{principal: int, tenure_months: int, roi_percent: float, projected_return: int, profit: int, maturity_date: string}
     */
    public function quote(int $principal, int $tenureMonths, float $roiPercent, ?CarbonInterface $start = null): array
    {
        $projected = (int) round($principal * (100 + $roiPercent) / 100);

        return [
            'principal' => $principal,
            'tenure_months' => $tenureMonths,
            'roi_percent' => $roiPercent,
            'projected_return' => $projected,
            'profit' => $projected - $principal,
            'maturity_date' => $this->maturityDate($tenureMonths, $start),
        ];
    }

    public function maturityDate(int $tenureMonths, ?CarbonInterface $start = null): string
    {
        return CarbonImmutable::parse($start ?? now())->startOfDay()->addMonthsNoOverflow($tenureMonths)->toDateString();
    }
}
