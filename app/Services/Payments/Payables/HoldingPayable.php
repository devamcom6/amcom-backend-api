<?php

namespace App\Services\Payments\Payables;

use App\Enums\DocumentType;
use App\Enums\HoldingStatus;
use App\Exceptions\ApiException;
use App\Models\HoldingPayment;
use App\Models\PropertyHolding;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Documents\DocumentService;
use App\Services\Notifier;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Rent / lease payments and renewals. The amount is the holding's next due rate.
 *
 * @implements Payable<PropertyHolding>
 */
class HoldingPayable implements Payable
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly Notifier $notifier,
    ) {}

    public function find(User $user, int $id): Model
    {
        return PropertyHolding::query()->where('user_id', $user->id)->findOrFail($id);
    }

    public function assertPayable(Model $payable): void
    {
        if (! $payable->isPayable()) {
            throw new ApiException('There is no payment due on this property.', 'invalid_state', 422);
        }
    }

    public function amount(Model $payable): int
    {
        return (int) $payable->rate;
    }

    public function description(Model $payable): string
    {
        $label = $payable->holding_type->value === 'lease' ? 'Lease renewal' : 'Rent payment';

        return $label.' — '.$payable->property?->title;
    }

    public function onInitialized(Model $payable, Transaction $transaction): void {}

    public function onProcessing(Model $payable, Transaction $transaction): void {}

    public function onSuccess(Model $payable, Transaction $transaction): void
    {
        $base = CarbonImmutable::parse($payable->period_end ?? now())->max(now()->subDay()->startOfDay());
        $newEnd = $payable->rate_period === 'month' ? $base->addMonthNoOverflow() : $base->addYearNoOverflow();

        HoldingPayment::query()->firstOrCreate(
            ['transaction_id' => $transaction->id],
            [
                'property_holding_id' => $payable->id,
                'date' => ($transaction->paid_at ?? now())->toDateString(),
                'description' => $payable->holding_type->value === 'lease' ? 'Renewal' : 'Rent',
                'amount' => $transaction->amount,
                'status' => 'paid',
            ],
        );

        $payable->forceFill([
            'status' => HoldingStatus::Active,
            'period_start' => $payable->period_end ? CarbonImmutable::parse($payable->period_end)->addDay()->toDateString() : now()->toDateString(),
            'period_end' => $newEnd->toDateString(),
            'next_payment_date' => $newEnd->addDay()->toDateString(),
            'renewal_date' => $newEnd->subDays(30)->toDateString(),
        ])->save();
    }

    public function onFailure(Model $payable, Transaction $transaction): void {}

    public function afterSuccess(Model $payable, Transaction $transaction): void
    {
        $payable->refresh();
        $user = $payable->user;

        $receipt = $this->documents->generate($payable, $user, DocumentType::PaymentReceipt, 'pdf.receipt', [
            'title' => 'Payment receipt',
            'transaction' => $transaction,
            'user' => $user,
            'subjectReference' => $payable->property?->reference,
        ], name: 'Payment receipt '.Format::date($transaction->paid_at));

        $this->notifier->user($user, 'holding_payment_received', [
            'amount' => Format::naira($transaction->amount),
            'property_title' => $payable->property?->title,
            'period_end' => Format::dateLong($payable->period_end),
            'holding_id' => $payable->id,
        ], [$receipt->id]);
    }

    public function summarySections(Model $payable, Transaction $transaction): array
    {
        return [
            [
                ['label' => 'Property', 'value' => (string) $payable->property?->title],
                ['label' => 'Payment', 'value' => $this->description($payable)],
            ],
            [
                ['label' => 'Amount paid', 'value' => Format::naira($transaction->amount), 'emphasis' => 'strong'],
                ['label' => 'Payment date', 'value' => Format::date($transaction->paid_at)],
            ],
            [['label' => 'Period ends', 'value' => Format::dateLong($payable->period_end), 'emphasis' => 'strong']],
        ];
    }

    public function nextUrl(Model $payable): ?string
    {
        return '/dashboard/property-management/holdings/'.$payable->id;
    }

    public function pendingUrl(): string
    {
        return '/dashboard/property-management';
    }

    public function retryPath(Model $payable): string
    {
        return '/dashboard/property-management/holdings/'.$payable->id;
    }
}
