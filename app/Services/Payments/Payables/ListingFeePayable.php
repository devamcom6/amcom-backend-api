<?php

namespace App\Services\Payments\Payables;

use App\Enums\DocumentType;
use App\Enums\ListingStatus;
use App\Exceptions\ApiException;
use App\Models\Property;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Documents\DocumentService;
use App\Services\Listings\ListingService;
use App\Services\Notifier;
use App\Services\SettingsService;
use App\Support\Format;
use Illuminate\Database\Eloquent\Model;

/** @implements Payable<Property> */
class ListingFeePayable implements Payable
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly ListingService $listings,
        private readonly DocumentService $documents,
        private readonly Notifier $notifier,
    ) {}

    public function find(User $user, int $id): Model
    {
        return Property::query()->ownedBy($user)->findOrFail($id);
    }

    public function assertPayable(Model $payable): void
    {
        if ($payable->fee_paid_at !== null) {
            throw ApiException::conflict('The listing fee has already been paid.', 'invalid_state');
        }

        if ($payable->status !== ListingStatus::AwaitingPayment) {
            throw new ApiException('Submit your listing before paying the registration fee.', 'invalid_state', 422);
        }
    }

    public function amount(Model $payable): int
    {
        return $this->settings->listingFee();
    }

    public function description(Model $payable): string
    {
        return 'Listing registration fee — '.$payable->title;
    }

    public function onInitialized(Model $payable, Transaction $transaction): void {}

    public function onProcessing(Model $payable, Transaction $transaction): void {}

    public function onSuccess(Model $payable, Transaction $transaction): void
    {
        $this->listings->markFeePaid($payable);
    }

    public function onFailure(Model $payable, Transaction $transaction): void {}

    public function afterSuccess(Model $payable, Transaction $transaction): void
    {
        $user = $payable->owner;

        $receipt = $this->documents->generate($payable, $user, DocumentType::PaymentReceipt, 'pdf.receipt', [
            'title' => 'Payment receipt',
            'transaction' => $transaction,
            'user' => $user,
            'subjectReference' => $payable->reference,
        ]);

        $this->notifier->user($user, 'payment_successful', [
            'amount' => Format::naira($transaction->amount),
            'description' => $transaction->description,
            'reference' => $transaction->reference,
            'next_url' => $this->nextUrl($payable),
        ], [$receipt->id]);

        $this->listings->notifySubmitted($payable);
    }

    public function summarySections(Model $payable, Transaction $transaction): array
    {
        return [
            [
                ['label' => 'Listing', 'value' => $payable->title],
                ['label' => 'Reference', 'value' => $payable->reference],
            ],
            [
                ['label' => 'Registration fee', 'value' => Format::naira($transaction->amount), 'emphasis' => 'strong'],
                ['label' => 'Payment date', 'value' => Format::date($transaction->paid_at)],
            ],
            [['label' => 'Next step', 'value' => 'Inspection & verification within 3–5 business days']],
        ];
    }

    public function nextUrl(Model $payable): ?string
    {
        return '/dashboard/property-management/listings/'.$payable->id;
    }

    public function pendingUrl(): string
    {
        return '/dashboard/property-management';
    }

    public function retryPath(Model $payable): string
    {
        return '/list-property/'.$payable->id.'/edit?step=confirm';
    }
}
