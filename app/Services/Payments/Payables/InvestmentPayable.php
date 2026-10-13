<?php

namespace App\Services\Payments\Payables;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\InvestmentStatus;
use App\Enums\Product;
use App\Exceptions\ApiException;
use App\Models\Investment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Documents\DocumentService;
use App\Services\Investments\AgreementService;
use App\Services\Investments\BuybackService;
use App\Services\Investments\CapitalService;
use App\Services\Investments\QuoteCalculator;
use App\Services\Notifier;
use App\Support\Format;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Buyback orders and capital investments (same table, product-specific effects).
 *
 * @implements Payable<Investment>
 */
class InvestmentPayable implements Payable
{
    public function __construct(
        private readonly Product $product,
        private readonly BuybackService $buyback,
        private readonly CapitalService $capital,
        private readonly AgreementService $agreements,
        private readonly DocumentService $documents,
        private readonly QuoteCalculator $quotes,
        private readonly Notifier $notifier,
    ) {}

    public function find(User $user, int $id): Model
    {
        return Investment::query()->ownedBy($user)->where('product', $this->product)->findOrFail($id);
    }

    public function assertPayable(Model $payable): void
    {
        match ($payable->status) {
            InvestmentStatus::PendingSignature => throw new ApiException(
                'Please review and sign the agreement before paying.',
                'agreement_unsigned',
                422,
            ),
            InvestmentStatus::PendingPayment, InvestmentStatus::PaymentProcessing => null,
            InvestmentStatus::Cancelled => throw ApiException::conflict('This order has expired or was cancelled. Please start a new one.', 'invalid_state'),
            default => throw ApiException::conflict('This order has already been paid.', 'invalid_state'),
        };
    }

    public function amount(Model $payable): int
    {
        return $payable->principal;
    }

    public function description(Model $payable): string
    {
        if ($payable->isBuyback()) {
            $count = $payable->plots()->count();

            return 'Buyback — '.$payable->estate?->name.' ('.$count.' '.($count === 1 ? 'plot' : 'plots').')';
        }

        return 'Capital investment — '.$payable->planName();
    }

    public function onInitialized(Model $payable, Transaction $transaction): void
    {
        if ($payable->isBuyback()) {
            $this->buyback->extendReservation($payable);
        }
    }

    public function onProcessing(Model $payable, Transaction $transaction): void
    {
        if ($payable->status === InvestmentStatus::PendingPayment) {
            $payable->forceFill(['status' => InvestmentStatus::PaymentProcessing])->save();
        }
    }

    public function onSuccess(Model $payable, Transaction $transaction): void
    {
        $start = $transaction->paid_at ?? now();

        if ($payable->isBuyback() && ! $this->buyback->markPlotsSold($payable)) {
            // A lapsed reservation was taken by someone else: money received, plots unavailable.
            $transaction->forceFill(['metadata' => array_merge($transaction->metadata ?? [], ['requires_refund' => true])])->save();
            Log::critical('payment.plots_lost_after_payment', ['investment_id' => $payable->id, 'reference' => $transaction->reference]);
            $this->notifier->ops('refund_required', 'Refund required: '.$transaction->reference, [
                'order' => $payable->reference,
                'amount' => Format::naira($transaction->amount),
                'reason' => 'Payment confirmed after the plot reservation lapsed and the plots were taken.',
            ], '/admin/transactions?search='.$transaction->reference);

            return;
        }

        $payable->forceFill([
            'status' => InvestmentStatus::Active,
            'started_at' => $start->toDateString(),
            'maturity_date' => $this->quotes->maturityDate($payable->tenure_months, $start),
            'reservation_expires_at' => null,
            'cancelled_at' => null,
        ])->save();
    }

    public function onFailure(Model $payable, Transaction $transaction): void
    {
        if ($payable->status === InvestmentStatus::PaymentProcessing) {
            $payable->forceFill(['status' => InvestmentStatus::PendingPayment])->save();
        }
    }

    public function afterSuccess(Model $payable, Transaction $transaction): void
    {
        $payable->refresh();

        if ($payable->status !== InvestmentStatus::Active) {
            return;
        }

        $user = $payable->user;
        $receipt = $this->documents->generate($payable, $user, DocumentType::PaymentReceipt, 'pdf.receipt', [
            'title' => 'Payment receipt',
            'transaction' => $transaction,
            'user' => $user,
            'subjectReference' => $payable->reference,
        ]);

        // Ensure the executed agreement exists and is marked available.
        $agreement = $this->agreements->agreementDocument($payable);
        $agreement->forceFill(['status' => DocumentStatus::Available])->save();

        $issued = [$receipt->name, $agreement->name];

        if ($payable->isBuyback()) {
            $this->documents->placeholder($payable, $user, DocumentType::PostDatedCheque, DocumentStatus::IssuedAfterSigning);
            $issued[] = DocumentType::PostDatedCheque->label().' (issued after signing)';
        } else {
            foreach ($this->capital->issueDocuments($payable) as $doc) {
                $issued[] = $doc->name;
            }
        }

        $next = $this->nextUrl($payable);

        $this->notifier->user($user, 'payment_successful', [
            'amount' => Format::naira($transaction->amount),
            'description' => $transaction->description,
            'reference' => $transaction->reference,
            'next_url' => $next,
        ], [$receipt->id]);

        $this->notifier->user($user, 'documents_ready', [
            'product_label' => $payable->isBuyback() ? 'buyback' : 'investment',
            'reference' => $payable->reference,
            'document_list' => implode(', ', $issued),
            'next_url' => $next,
        ], [$agreement->id]);

        $this->notifier->ops('payment_received', 'Payment received: '.$payable->reference, [
            'customer' => $user->displayName().' <'.$user->email.'>',
            'amount' => Format::naira($transaction->amount),
            'description' => $transaction->description,
        ], '/admin/investments?search='.$payable->reference);
    }

    public function summarySections(Model $payable, Transaction $transaction): array
    {
        $amountPaid = [
            ['label' => 'Amount paid', 'value' => Format::naira($transaction->amount)],
            ['label' => 'Payment date', 'value' => Format::date($transaction->paid_at)],
        ];
        $maturity = [['label' => 'Maturity date', 'value' => Format::dateLong($payable->maturity_date), 'emphasis' => 'strong']];

        if ($payable->isBuyback()) {
            $plots = $payable->plots;

            return [
                [
                    ['label' => 'Estate', 'value' => (string) $payable->estate?->name],
                    ['label' => 'Plots ('.$plots->count().')', 'value' => $plots->map(fn ($p) => $p->code.' · '.$p->size_sqm.' sqm')->implode("\n")],
                ],
                [
                    ['label' => 'Price', 'value' => Format::naira($payable->principal), 'emphasis' => 'strong'],
                    ['label' => 'Tenure', 'value' => Format::months($payable->tenure_months)],
                    ['label' => 'Projected return', 'value' => Format::naira($payable->projected_return)],
                    ['label' => 'Profit', 'value' => Format::naira($payable->profit(), true), 'emphasis' => 'positive'],
                ],
                $amountPaid,
                $maturity,
            ];
        }

        return [
            [['label' => 'Tenure', 'value' => Format::months($payable->tenure_months)]],
            [
                ['label' => 'Investment amount', 'value' => Format::naira($payable->principal), 'emphasis' => 'strong'],
                ['label' => 'Interest Rate', 'value' => Format::percent($payable->roi_percent)],
                ['label' => 'Projected return', 'value' => Format::naira($payable->projected_return)],
                ['label' => 'Profit', 'value' => Format::naira($payable->profit(), true), 'emphasis' => 'positive'],
            ],
            $amountPaid,
            $maturity,
        ];
    }

    public function nextUrl(Model $payable): ?string
    {
        return $this->product === Product::Buyback
            ? '/dashboard/buyback/'.$payable->id
            : '/dashboard/capital-investment/'.$payable->id;
    }

    public function pendingUrl(): string
    {
        return $this->product === Product::Buyback ? '/dashboard/buyback' : '/dashboard/capital-investment';
    }

    public function retryPath(Model $payable): string
    {
        return $payable->isBuyback()
            ? '/buyback/estates/'.$payable->estate?->slug.'/checkout?step=payment&order='.$payable->id
            : '/capital-investment/invest?step=payment&order='.$payable->id;
    }
}
