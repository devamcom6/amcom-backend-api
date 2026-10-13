<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Models\WebhookEvent;
use App\Services\Payments\PaymentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processes a stored, signature-verified Paystack event. Charge events are re-verified
 * against the Paystack API before any business state changes (defence in depth).
 */
class ProcessPaystackWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    /** @var list<int> */
    public array $backoff = [30, 120, 300, 900, 3600];

    public function __construct(public readonly int $webhookEventId) {}

    public function handle(PaymentService $payments): void
    {
        $event = WebhookEvent::find($this->webhookEventId);

        if ($event === null || $event->status === 'processed') {
            return;
        }

        $event->increment('attempts');
        $data = $event->payload['data'] ?? [];

        try {
            match (true) {
                in_array($event->event, ['charge.success', 'charge.failed'], true) => $this->handleCharge($payments, $data),
                $event->event === 'refund.processed' => $this->handleRefund($payments, $data),
                str_starts_with($event->event, 'transfer.') => Log::info('paystack.transfer_event', ['event' => $event->event, 'reference' => $data['reference'] ?? null, 'status' => $data['status'] ?? null]),
                default => Log::info('paystack.webhook_ignored', ['event' => $event->event]),
            };

            $event->forceFill(['status' => 'processed', 'processed_at' => now(), 'error' => null])->save();
        } catch (Throwable $e) {
            $event->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 250)])->save();
            Log::error('paystack.webhook_failed', ['event_id' => $event->id, 'event' => $event->event, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function handleCharge(PaymentService $payments, array $data): void
    {
        $transaction = Transaction::query()->where('reference', (string) ($data['reference'] ?? ''))->first();

        if ($transaction === null) {
            Log::warning('paystack.webhook_unknown_reference', ['reference' => $data['reference'] ?? null]);

            return;
        }

        $payments->refresh($transaction);
    }

    private function handleRefund(PaymentService $payments, array $data): void
    {
        $reference = (string) ($data['transaction_reference'] ?? $data['transaction']['reference'] ?? '');
        $transaction = Transaction::query()->where('reference', $reference)->first();

        if ($transaction !== null) {
            $payments->markReversed($transaction, 'Refund processed');
        }
    }
}
