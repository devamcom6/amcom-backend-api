<?php

namespace App\Services\Investments;

use App\Enums\DocumentType;
use App\Enums\InvestmentStatus;
use App\Enums\Product;
use App\Exceptions\ApiException;
use App\Models\Investment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Documents\DocumentService;
use App\Services\Notifier;
use App\Services\SettingsService;
use App\Support\Format;
use App\Support\Reference;
use Illuminate\Support\Facades\DB;

class CapitalService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly QuoteCalculator $quotes,
        private readonly DocumentService $documents,
        private readonly Notifier $notifier,
        private readonly AuditLogger $audit,
    ) {}

    /** Public plan configuration (contract §7.1). */
    public function plan(): array
    {
        $plan = $this->settings->capitalPlan();

        return [
            'min_amount' => (int) $plan['min_amount'],
            'max_self_serve_amount' => (int) $plan['max_self_serve_amount'],
            'tenures' => array_map(fn ($t) => ['months' => (int) $t['months'], 'roi_percent' => (float) $t['roi_percent']], $plan['tenures']),
            'checkout_mode' => $plan['checkout_mode'],
            'pricing_examples' => array_map('intval', $plan['pricing_examples'] ?? []),
        ];
    }

    public function roiFor(int $months): float
    {
        $tenure = collect($this->plan()['tenures'])->firstWhere('months', $months);

        if ($tenure === null) {
            throw ApiException::field('tenure_months', 'The selected tenure is not available.');
        }

        return (float) $tenure['roi_percent'];
    }

    public function assertAmount(int $amount): void
    {
        $plan = $this->plan();

        if ($amount < $plan['min_amount']) {
            throw ApiException::field('amount', 'The minimum investment amount is '.Format::naira($plan['min_amount']).'.');
        }

        if ($amount > $plan['max_self_serve_amount']) {
            throw ApiException::field('amount', 'For investments above '.Format::naira($plan['max_self_serve_amount']).', contact our investment team directly.');
        }
    }

    public function createInvestment(User $user, int $amount, int $tenureMonths): Investment
    {
        if ($this->plan()['checkout_mode'] === 'interest') {
            throw new ApiException('Capital investments are currently handled by our team. Please submit your interest instead.', 'checkout_mode_interest', 422);
        }

        $this->assertAmount($amount);
        $roi = $this->roiFor($tenureMonths);
        $quote = $this->quotes->quote($amount, $tenureMonths, $roi);

        return DB::transaction(function () use ($user, $amount, $tenureMonths, $roi, $quote) {
            $investment = new Investment([
                'product' => Product::CapitalInvestment,
                'reference' => Reference::temporary(),
                'status' => InvestmentStatus::PendingSignature,
                'principal' => $amount,
                'tenure_months' => $tenureMonths,
                'roi_percent' => $roi,
                'projected_return' => $quote['projected_return'],
                'maturity_date' => $quote['maturity_date'],
            ]);
            $user->investments()->save($investment);
            $investment->forceFill(['reference' => Reference::yearly('CI', $investment->id)])->save();

            return $investment;
        });
    }

    public function requestPayout(User $user, Investment $investment): Investment
    {
        if ($investment->status !== InvestmentStatus::Matured) {
            throw new ApiException('This investment has not matured yet.', 'not_matured', 422);
        }

        if ($investment->payout_requested_at !== null) {
            return $investment; // idempotent
        }

        $account = $user->payoutAccounts()->where('is_default', true)->first();

        if ($account === null) {
            throw ApiException::field('payout_account', 'Add a payout account in Settings before requesting a payout.');
        }

        $investment->forceFill(['payout_requested_at' => now()])->save();

        $this->audit->log('investment.payout_requested', $investment, null, ['payout_account_id' => $account->id], actor: $user);

        $this->notifier->user($user, 'payout_requested', [
            'reference' => $investment->reference,
            'amount' => Format::naira($investment->projected_return),
            'bank_name' => $account->bank_name,
            'account_masked' => $account->maskedNumber(),
        ]);
        $this->notifier->ops('payout_requested', 'Payout requested: '.$investment->reference, [
            'customer' => $user->displayName().' <'.$user->email.'>',
            'amount' => Format::naira($investment->projected_return),
            'bank' => $account->bank_name.' '.$account->maskedNumber().' ('.$account->account_name.')',
        ], '/admin/investments?search='.$investment->reference);

        return $investment->fresh();
    }

    /**
     * Rolls a matured position (principal + return) into a new active position.
     * The old position is closed as paid out (reinvested).
     */
    public function reinvest(User $user, Investment $investment, int $tenureMonths): Investment
    {
        if (! $investment->canReinvest()) {
            throw ApiException::conflict('This investment cannot be reinvested.', 'invalid_state');
        }

        $roi = $this->roiFor($tenureMonths);
        $principal = $investment->projected_return;
        $quote = $this->quotes->quote($principal, $tenureMonths, $roi);

        $new = DB::transaction(function () use ($user, $investment, $tenureMonths, $roi, $principal, $quote) {
            $investment->forceFill(['status' => InvestmentStatus::PaidOut, 'paid_out_at' => now()])->save();

            $new = new Investment([
                'product' => Product::CapitalInvestment,
                'reference' => Reference::temporary(),
                'status' => InvestmentStatus::Active,
                'principal' => $principal,
                'tenure_months' => $tenureMonths,
                'roi_percent' => $roi,
                'projected_return' => $quote['projected_return'],
                'started_at' => now()->toDateString(),
                'maturity_date' => $quote['maturity_date'],
                'agreement_signed_at' => now(),
                'reinvested_from_id' => $investment->id,
            ]);
            $user->investments()->save($new);
            $new->forceFill(['reference' => Reference::yearly('CI', $new->id)])->save();

            return $new;
        });

        $this->documents->generate($new, $user, DocumentType::InvestmentCertificate, 'pdf.certificate', ['investment' => $new, 'title' => 'Investment Certificate']);
        $this->audit->log('investment.reinvested', $new, ['from' => $investment->reference], ['reference' => $new->reference, 'principal' => $principal], actor: $user);

        return $new->fresh();
    }

    /** Documents issued after a verified capital payment. */
    public function issueDocuments(Investment $investment): array
    {
        $documents = [];
        $documents[] = $this->documents->generate($investment, $investment->user, DocumentType::InvestmentCertificate, 'pdf.certificate', [
            'investment' => $investment,
            'title' => 'Investment Certificate',
        ]);

        return $documents;
    }
}
