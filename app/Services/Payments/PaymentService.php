<?php

namespace App\Services\Payments;

use App\Enums\PayableType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Notifier;
use App\Support\Format;
use App\Support\Reference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Payment orchestration (contract §11). Business state only changes from data verified
 * with Paystack (webhook or server-side verify) — never from the browser redirect.
 */
class PaymentService
{
    /** Reuse an unfinished checkout for the same payable within this window (idempotent initialise). */
    private const REUSE_WINDOW_MINUTES = 30;

    public function __construct(
        private readonly PaystackService $paystack,
        private readonly PayableRegistry $payables,
        private readonly Notifier $notifier,
        private readonly AuditLogger $audit,
    ) {}

    public function initialize(User $user, PayableType $type, int $payableId, PaymentMethod $method, ?string $callbackUrl): Transaction
    {
        $handler = $this->payables->for($type);

        try {
            $payable = $handler->find($user, $payableId);
        } catch (ModelNotFoundException) {
            throw ApiException::field('payable_id', 'The selected item was not found.');
        }

        $handler->assertPayable($payable);

        $amount = $handler->amount($payable);
        $callbackUrl = $this->resolveCallbackUrl($callbackUrl);

        $existing = Transaction::query()
            ->where('user_id', $user->id)
            ->where('payable_type', $type)
            ->where('payable_id', $payable->getKey())
            ->where('method', $method)
            ->where('amount', $amount)
            ->where('status', PaymentStatus::Pending)
            ->whereNotNull('authorization_url')
            ->where('created_at', '>=', now()->subMinutes(self::REUSE_WINDOW_MINUTES))
            ->latest('id')
            ->first();

        if ($existing) {
            $handler->onInitialized($payable, $existing);

            return $existing;
        }

        $transaction = Transaction::create([
            'user_id' => $user->id,
            'reference' => Reference::payment(),
            'provider' => 'paystack',
            'payable_type' => $type,
            'payable_id' => $payable->getKey(),
            'description' => $handler->description($payable),
            'amount' => $amount,
            'currency' => 'NGN',
            'method' => $method,
            'status' => PaymentStatus::Pending,
            'callback_url' => $callbackUrl,
            'metadata' => ['payable_type' => $type->value, 'payable_id' => $payable->getKey(), 'user_id' => $user->id],
        ]);

        try {
            $result = $this->paystack->initializeTransaction(
                email: $user->email,
                amountKobo: $transaction->amountInKobo(),
                reference: $transaction->reference,
                callbackUrl: $callbackUrl,
                channels: [$method->value],
                metadata: [
                    'payable_type' => $type->value,
                    'payable_id' => $payable->getKey(),
                    'user_id' => $user->id,
                    'custom_fields' => [
                        ['display_name' => 'Description', 'variable_name' => 'description', 'value' => $transaction->description],
                    ],
                ],
            );
        } catch (Throwable $e) {
            $transaction->forceFill(['status' => PaymentStatus::Failed, 'gateway_response' => 'Initialisation failed', 'failed_at' => now()])->save();
            throw $e;
        }

        $transaction->forceFill([
            'authorization_url' => $result['authorization_url'],
            'access_code' => $result['access_code'],
        ])->save();

        $handler->onInitialized($payable, $transaction);

        Log::info('payment.initialized', ['reference' => $transaction->reference, 'payable_type' => $type->value, 'payable_id' => $payable->getKey(), 'amount' => $amount]);

        return $transaction;
    }

    public function findForUser(User $user, string $reference): Transaction
    {
        $transaction = Transaction::query()->where('reference', $reference)->where('user_id', $user->id)->first();

        if ($transaction === null) {
            throw ApiException::notFound('We could not find this payment.', 'payment_not_found');
        }

        return $transaction;
    }

    /** Re-queries Paystack for a non-final transaction and applies the result. */
    public function refresh(Transaction $transaction): Transaction
    {
        if ($transaction->status->isFinal() && $transaction->status !== PaymentStatus::Success) {
            return $transaction;
        }

        if ($transaction->status === PaymentStatus::Success && $transaction->effects_applied_at !== null) {
            return $transaction;
        }

        $data = $this->paystack->verifyTransaction($transaction->reference);

        if ($data === null) {
            // Unknown to Paystack: customer never reached checkout.
            return $transaction;
        }

        return $this->applyGatewayResult($transaction, $data);
    }

    /** Admin re-query: always asks Paystack, whatever the local status (contract §15.6). */
    public function requery(Transaction $transaction): Transaction
    {
        $data = $this->paystack->verifyTransaction($transaction->reference);

        return $data === null ? $transaction : $this->applyGatewayResult($transaction, $data);
    }

    /**
     * Applies verified Paystack data idempotently (row lock + effects_applied_at guard).
     *
     * @param  array<string, mixed>  $data  `data` object of GET /transaction/verify
     */
    public function applyGatewayResult(Transaction $transaction, array $data): Transaction
    {
        $handler = $this->payables->for($transaction->payable_type);
        $outcome = null;

        DB::transaction(function () use ($transaction, $data, $handler, &$outcome) {
            /** @var Transaction $locked */
            $locked = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->first();
            $previous = $locked->status;
            $status = PaymentStatus::fromPaystack($data['status'] ?? null);

            if ($status === PaymentStatus::Success && ! $this->amountMatches($locked, $data)) {
                Log::critical('payment.amount_mismatch', [
                    'reference' => $locked->reference,
                    'expected_kobo' => $locked->amountInKobo(),
                    'received_kobo' => $data['amount'] ?? null,
                    'currency' => $data['currency'] ?? null,
                ]);
                $status = PaymentStatus::Failed;
                $data['gateway_response'] = 'Amount mismatch — flagged for review';
                $this->notifier->ops('amount_mismatch', 'Payment amount mismatch: '.$locked->reference, [
                    'expected' => Format::naira($locked->amount),
                    'received' => Format::naira(((int) ($data['amount'] ?? 0)) / 100),
                ], '/admin/transactions?search='.$locked->reference);
            }

            // Never downgrade a settled success (e.g. a late "abandoned" event).
            if ($previous === PaymentStatus::Success && $status !== PaymentStatus::Reversed) {
                $status = PaymentStatus::Success;
            }

            $locked->forceFill([
                'status' => $status,
                'provider_reference' => isset($data['id']) ? (string) $data['id'] : $locked->provider_reference,
                'gateway_response' => isset($data['gateway_response']) ? mb_substr((string) $data['gateway_response'], 0, 255) : $locked->gateway_response,
                'channel' => $data['channel'] ?? $locked->channel,
                'fee' => isset($data['fees']) ? (int) round(((int) $data['fees']) / 100) : $locked->fee,
                'authorization_data' => $this->safeAuthorization($data['authorization'] ?? null) ?? $locked->authorization_data,
                'paid_at' => $status === PaymentStatus::Success ? ($locked->paid_at ?? (isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : now())) : $locked->paid_at,
                'failed_at' => in_array($status, [PaymentStatus::Failed, PaymentStatus::Abandoned], true) ? ($locked->failed_at ?? now()) : $locked->failed_at,
                'verified_at' => now(),
            ])->save();

            $payable = $this->payableFor($locked);

            if ($payable === null) {
                Log::error('payment.payable_missing', ['reference' => $locked->reference]);

                return;
            }

            if ($status === PaymentStatus::Success && $locked->effects_applied_at === null) {
                $handler->onSuccess($payable, $locked);
                $locked->forceFill(['effects_applied_at' => now()])->save();
                $outcome = 'success';
            } elseif (in_array($status, [PaymentStatus::Failed, PaymentStatus::Abandoned], true) && $previous !== $status && ! $previous->isFinal()) {
                $handler->onFailure($payable, $locked);
                $outcome = $status === PaymentStatus::Failed ? 'failed' : null; // abandoned checkouts don't email
            } elseif ($status === PaymentStatus::Processing) {
                $handler->onProcessing($payable, $locked);
            }

            $transaction->setRawAttributes($locked->getAttributes(), true);
        });

        Log::info('payment.verified', ['reference' => $transaction->reference, 'status' => $transaction->status->value, 'outcome' => $outcome]);

        if ($outcome === 'success' && ($payable = $this->payableFor($transaction))) {
            try {
                $handler->afterSuccess($payable, $transaction);
            } catch (Throwable $e) {
                // Payment is recorded; documents/emails can be re-issued. Never fail verification for this.
                Log::error('payment.after_success_failed', ['reference' => $transaction->reference, 'error' => $e->getMessage()]);
            }
        }

        if ($outcome === 'failed' && ($payable = $this->payableFor($transaction))) {
            $this->notifier->user($transaction->user, 'payment_failed', [
                'amount' => Format::naira($transaction->amount),
                'description' => $transaction->description,
                'gateway_response' => $transaction->gateway_response ?: 'declined',
                'retry_path' => $handler->retryPath($payable),
            ]);
        }

        return $transaction;
    }

    public function markReversed(Transaction $transaction, ?string $reason = null): void
    {
        $old = $transaction->status->value;
        $transaction->forceFill(['status' => PaymentStatus::Reversed, 'gateway_response' => $reason ?? 'Refund processed'])->save();
        $this->audit->log('transaction.reversed', $transaction, ['status' => $old], ['status' => 'reversed'], 'Refund processed by Paystack', $transaction->user);
    }

    /** Payment result payload (contract §11.3). */
    public function verificationPayload(Transaction $transaction): array
    {
        $handler = $this->payables->for($transaction->payable_type);
        $payable = $this->payableFor($transaction);
        $success = $transaction->status === PaymentStatus::Success;

        return [
            'reference' => $transaction->reference,
            'status' => $transaction->status->value,
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
            'method' => $transaction->method->value,
            'paid_at' => Format::iso($transaction->paid_at),
            'gateway_response' => $transaction->gateway_response,
            'payable_type' => $transaction->payable_type->value,
            'payable_id' => $transaction->payable_id,
            'summary' => [
                'title' => $transaction->payable_type->label(),
                'sections' => $success && $payable ? $handler->summarySections($payable, $transaction) : [],
            ],
            'next_url' => match (true) {
                $payable === null => null,
                $success => $handler->nextUrl($payable),
                in_array($transaction->status, [PaymentStatus::Pending, PaymentStatus::Processing], true) => $handler->pendingUrl(),
                default => null,
            },
        ];
    }

    private function payableFor(Transaction $transaction): ?Model
    {
        try {
            return $this->payables->for($transaction->payable_type)->find($transaction->user, $transaction->payable_id);
        } catch (ModelNotFoundException) {
            return null;
        }
    }

    private function amountMatches(Transaction $transaction, array $data): bool
    {
        return (int) ($data['amount'] ?? -1) === $transaction->amountInKobo()
            && strtoupper((string) ($data['currency'] ?? 'NGN')) === $transaction->currency;
    }

    /** Keeps only non-sensitive descriptors; reusable authorization codes are never stored. */
    private function safeAuthorization(?array $authorization): ?array
    {
        if (! $authorization) {
            return null;
        }

        return array_filter([
            'channel' => $authorization['channel'] ?? null,
            'card_type' => $authorization['card_type'] ?? null,
            'brand' => $authorization['brand'] ?? null,
            'last4' => $authorization['last4'] ?? null,
            'bank' => $authorization['bank'] ?? null,
            'country_code' => $authorization['country_code'] ?? null,
        ]);
    }

    /** callback_url must be an allow-listed frontend origin + /payments/callback (contract §11.2). */
    private function resolveCallbackUrl(?string $callbackUrl): string
    {
        $allowed = config('amcom.frontend_urls');

        if ($callbackUrl === null || $callbackUrl === '') {
            return config('amcom.frontend_url').'/payments/callback';
        }

        $parts = parse_url($callbackUrl);
        $origin = isset($parts['scheme'], $parts['host'])
            ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')
            : null;

        if ($origin === null || ! in_array($origin, $allowed, true) || ($parts['path'] ?? '') !== '/payments/callback') {
            throw ApiException::field('callback_url', 'The callback URL is not allowed.');
        }

        return $origin.'/payments/callback';
    }
}
