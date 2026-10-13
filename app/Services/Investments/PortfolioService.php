<?php

namespace App\Services\Investments;

use App\Enums\InvestmentStatus;
use App\Enums\Product;
use App\Models\User;
use App\Support\Format;
use Illuminate\Support\Collection;

/** Customer portfolio aggregates (contract §6.8 / §7.5). */
class PortfolioService
{
    public function positions(User $user, Product $product): Collection
    {
        return $user->investments()
            ->where('product', $product)
            ->positions()
            ->with(['estate', 'plots', 'documents'])
            ->latest('started_at')
            ->latest('id')
            ->get();
    }

    public function summary(Collection $positions): array
    {
        $open = $positions->filter(fn ($p) => in_array($p->status, [InvestmentStatus::Active, InvestmentStatus::Matured], true));
        $invested = (int) $open->sum('principal');
        $nextMaturity = $positions
            ->filter(fn ($p) => $p->status === InvestmentStatus::Active && $p->maturity_date)
            ->sortBy('maturity_date')
            ->first();

        return [
            'total_invested' => $invested,
            'active_positions' => $positions->where('status', InvestmentStatus::Active)->count(),
            'projected_profit' => (int) $open->sum(fn ($p) => $p->profit()),
            'average_roi_percent' => $invested > 0
                ? round($open->sum(fn ($p) => $p->roi_percent * $p->principal) / $invested, 1)
                : 0,
            'total_expected_payout' => (int) $open->sum('projected_return'),
            'next_maturity_date' => Format::ymd($nextMaturity?->maturity_date),
            'days_to_next_maturity' => $nextMaturity?->daysToMaturity(),
        ];
    }
}
