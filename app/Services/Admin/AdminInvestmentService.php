<?php

namespace App\Services\Admin;

use App\Enums\InvestmentStatus;
use App\Exceptions\ApiException;
use App\Models\Investment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Investments\BuybackService;
use App\Services\Notifier;
use App\Support\Format;
use Illuminate\Support\Facades\DB;

class AdminInvestmentService
{
    public function __construct(
        private readonly BuybackService $buyback,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
    ) {}

    /** Allowed: pending → cancelled, matured → paid_out (contract §15.5). */
    public function changeStatus(User $actor, Investment $investment, InvestmentStatus $target): Investment
    {
        $old = $investment->status;

        if (! $old->canTransitionTo($target)) {
            throw new ApiException(
                "An investment that is {$old->value} cannot be changed to {$target->value}.",
                'invalid_state',
                409,
            );
        }

        DB::transaction(function () use ($investment, $target) {
            $investment->forceFill([
                'status' => $target,
                'cancelled_at' => $target === InvestmentStatus::Cancelled ? now() : $investment->cancelled_at,
                'paid_out_at' => $target === InvestmentStatus::PaidOut ? now() : $investment->paid_out_at,
            ])->save();

            if ($target === InvestmentStatus::Cancelled && $investment->isBuyback()) {
                $this->buyback->releasePlots($investment);
            }
        });

        $this->audit->log('investment.status_changed', $investment, ['status' => $old->value], ['status' => $target->value],
            $target === InvestmentStatus::Cancelled ? 'cancelled investment' : 'marked investment paid out', $actor);

        $label = $investment->isBuyback() ? 'buyback' : 'capital investment';

        if ($target === InvestmentStatus::Cancelled) {
            $this->notifier->user($investment->user, 'investment_cancelled', ['reference' => $investment->reference, 'product_label' => $label]);
        } else {
            $this->notifier->user($investment->user, 'payout_sent', ['reference' => $investment->reference, 'amount' => Format::naira($investment->projected_return)]);
        }

        return $investment->fresh();
    }
}
