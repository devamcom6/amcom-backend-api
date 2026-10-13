<?php

namespace App\Services\Investments;

use App\Enums\CheckoutMode;
use App\Enums\InvestmentStatus;
use App\Enums\PaymentStatus;
use App\Enums\PlotStatus;
use App\Enums\Product;
use App\Exceptions\ApiException;
use App\Models\Estate;
use App\Models\EstateTenure;
use App\Models\Investment;
use App\Models\Plot;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Reference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BuybackService
{
    public function __construct(private readonly QuoteCalculator $quotes) {}

    /**
     * Creates a direct-mode order: computes the authoritative quote and reserves the plots
     * (row locks prevent two customers reserving the same plot).
     *
     * @param  list<int>  $plotIds
     */
    public function createOrder(User $user, Estate $estate, array $plotIds, int $tenureMonths): Investment
    {
        if ($estate->checkout_mode === CheckoutMode::Interest) {
            throw new ApiException(
                'This estate is sold through our agents. Please submit your interest instead.',
                'checkout_mode_interest',
                422,
            );
        }

        $estate->loadMissing('tenures');
        $tenure = $this->assertSelection($estate, $plotIds, $tenureMonths);

        return DB::transaction(function () use ($user, $estate, $plotIds, $tenure) {
            $plots = Plot::query()
                ->where('estate_id', $estate->id)
                ->whereIn('id', $plotIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($plots as $plot) {
                if ($plot->effectiveStatus() !== PlotStatus::Available) {
                    throw ApiException::conflict("Plot {$plot->code} is no longer available. Please choose another.", 'plot_unavailable');
                }
            }

            $principal = (int) $plots->sum('price');
            $quote = $this->quotes->quote($principal, $tenure->months, $tenure->roi_percent);
            $expiresAt = now()->addMinutes((int) config('amcom.buyback.reservation_minutes'));

            $investment = new Investment([
                'product' => Product::Buyback,
                'reference' => Reference::temporary(),
                'status' => InvestmentStatus::PendingSignature,
                'estate_id' => $estate->id,
                'principal' => $principal,
                'tenure_months' => $tenure->months,
                'roi_percent' => $tenure->roi_percent,
                'projected_return' => $quote['projected_return'],
                'maturity_date' => $quote['maturity_date'],
                'reservation_expires_at' => $expiresAt,
            ]);
            $user->investments()->save($investment);
            $investment->forceFill(['reference' => Reference::yearly('BB', $investment->id)])->save();

            $investment->plots()->attach($plots->mapWithKeys(fn (Plot $p) => [$p->id => ['price' => $p->price]])->all());

            Plot::query()->whereKey($plots->modelKeys())->update([
                'status' => PlotStatus::Reserved,
                'reserved_by_investment_id' => $investment->id,
                'reserved_until' => $expiresAt,
            ]);

            Log::info('buyback.order_created', ['investment_id' => $investment->id, 'user_id' => $user->id, 'plots' => $plots->pluck('code')]);

            return $investment->load(['estate', 'plots']);
        });
    }

    /** Validates estate, tenure and plot selection (shared by orders and interests). */
    public function assertSelection(Estate $estate, array $plotIds, int $tenureMonths): EstateTenure
    {
        $tenure = $estate->tenureFor($tenureMonths);

        if ($tenure === null) {
            throw ApiException::field('tenure_months', 'The selected tenure is not available for this estate.');
        }

        if (count($plotIds) > $estate->max_plots_per_order) {
            throw ApiException::field('plot_ids', "You can select up to {$estate->max_plots_per_order} plots per order.");
        }

        $owned = Plot::query()->where('estate_id', $estate->id)->whereIn('id', $plotIds)->count();

        if ($owned !== count(array_unique($plotIds))) {
            throw ApiException::field('plot_ids', 'One or more selected plots do not belong to this estate.');
        }

        return $tenure;
    }

    /**
     * Cancels unpaid orders whose reservation lapsed and frees their plots (scheduled every minute).
     * Orders with a payment in flight are left alone until that payment settles.
     */
    public function releaseExpiredReservations(): int
    {
        $released = 0;

        Investment::query()
            ->where('product', Product::Buyback)
            ->whereIn('status', [InvestmentStatus::PendingSignature, InvestmentStatus::PendingPayment])
            ->where('reservation_expires_at', '<', now())
            ->orderBy('id')
            ->chunkById(100, function ($investments) use (&$released) {
                foreach ($investments as $investment) {
                    $inFlight = Transaction::query()
                        ->where('payable_type', 'buyback_order')
                        ->where('payable_id', $investment->id)
                        ->where('status', PaymentStatus::Processing)
                        ->exists();

                    if ($inFlight) {
                        continue;
                    }

                    $this->cancel($investment, 'reservation_expired');
                    $released++;
                }
            });

        return $released;
    }

    public function cancel(Investment $investment, string $reason): void
    {
        DB::transaction(function () use ($investment) {
            $investment->forceFill(['status' => InvestmentStatus::Cancelled, 'cancelled_at' => now()])->save();
            $this->releasePlots($investment);
        });

        Log::info('buyback.order_cancelled', ['investment_id' => $investment->id, 'reason' => $reason]);
    }

    public function releasePlots(Investment $investment): void
    {
        Plot::query()
            ->where('reserved_by_investment_id', $investment->id)
            ->where('status', PlotStatus::Reserved)
            ->update(['status' => PlotStatus::Available, 'reserved_by_investment_id' => null, 'reserved_until' => null]);
    }

    /** Extends the hold while the customer is at checkout (called on payment initialisation). */
    public function extendReservation(Investment $investment): void
    {
        $until = now()->addMinutes((int) config('amcom.buyback.reservation_minutes'));

        DB::transaction(function () use ($investment, $until) {
            $investment->forceFill(['reservation_expires_at' => $until])->save();
            Plot::query()->where('reserved_by_investment_id', $investment->id)->update(['reserved_until' => $until]);
        });
    }

    /**
     * Marks the order's plots sold after a verified payment. Returns false if a plot was lost
     * to someone else meanwhile (the payment then needs a manual refund).
     */
    public function markPlotsSold(Investment $investment): bool
    {
        $plots = $investment->plots()->lockForUpdate()->get();

        foreach ($plots as $plot) {
            $ownedByOrder = $plot->reserved_by_investment_id === $investment->id;
            $free = $plot->effectiveStatus() === PlotStatus::Available;

            if (! $ownedByOrder && ! $free) {
                return false;
            }
        }

        Plot::query()->whereKey($plots->modelKeys())->update([
            'status' => PlotStatus::Sold,
            'reserved_by_investment_id' => $investment->id,
            'reserved_until' => null,
        ]);

        return true;
    }
}
