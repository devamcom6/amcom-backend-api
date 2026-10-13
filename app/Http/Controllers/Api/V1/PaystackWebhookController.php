<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPaystackWebhook;
use App\Models\WebhookEvent;
use App\Services\Payments\PaystackService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/v1/webhooks/paystack (contract §11.4).
 * Verifies the HMAC-SHA512 signature over the raw body, stores the event once per
 * idempotency key, queues processing and answers 200 quickly.
 */
class PaystackWebhookController extends Controller
{
    public function __invoke(Request $request, PaystackService $paystack): JsonResponse
    {
        $payload = $request->getContent();

        $allowedIps = config('services.paystack.webhook_ips');
        if ($allowedIps !== [] && ! in_array($request->ip(), $allowedIps, true)) {
            Log::warning('paystack.webhook_ip_rejected', ['ip' => $request->ip()]);

            return new JsonResponse(['success' => false, 'message' => 'Forbidden.', 'code' => 'forbidden'], 403);
        }

        if (! $paystack->isValidSignature($payload, $request->header('x-paystack-signature'))) {
            Log::warning('paystack.webhook_invalid_signature', ['ip' => $request->ip()]);

            return new JsonResponse(['success' => false, 'message' => 'Invalid signature.', 'code' => 'invalid_signature'], 401);
        }

        $body = json_decode($payload, true);

        if (! is_array($body) || ! isset($body['event'])) {
            return new JsonResponse(['success' => false, 'message' => 'Malformed payload.', 'code' => 'validation_failed'], 400);
        }

        $event = (string) $body['event'];
        $data = $body['data'] ?? [];
        $reference = (string) ($data['reference'] ?? $data['transaction_reference'] ?? $data['transfer_code'] ?? '');
        $key = $event.':'.($reference !== '' ? $reference.':'.($data['id'] ?? '') : hash('sha256', $payload));

        try {
            $record = WebhookEvent::create([
                'provider' => 'paystack',
                'event' => $event,
                'idempotency_key' => mb_substr($key, 0, 128),
                'reference' => $reference ?: null,
                'payload' => $body,
            ]);
        } catch (UniqueConstraintViolationException) {
            $record = WebhookEvent::query()->where('idempotency_key', mb_substr($key, 0, 128))->first();

            if ($record === null || $record->status === 'processed') {
                Log::info('paystack.webhook_duplicate', ['event' => $event, 'reference' => $reference]);

                return new JsonResponse(['success' => true, 'message' => 'Already processed.', 'data' => null]);
            }
        }

        ProcessPaystackWebhook::dispatch($record->id);

        return new JsonResponse(['success' => true, 'message' => 'Received.', 'data' => null]);
    }
}
