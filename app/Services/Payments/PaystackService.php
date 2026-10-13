<?php

namespace App\Services\Payments;

use App\Exceptions\ExternalServiceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The only class that talks to Paystack. Uses the secret key server-side only.
 * Amounts passed in/out of this class are in KOBO (Paystack's unit).
 */
class PaystackService
{
    public function __construct(private readonly array $config) {}

    public function isConfigured(): bool
    {
        return filled($this->config['secret_key'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<string>  $channels
     * @return array{authorization_url: string, access_code: string, reference: string}
     */
    public function initializeTransaction(
        string $email,
        int $amountKobo,
        string $reference,
        string $callbackUrl,
        array $channels,
        array $metadata,
        string $currency = 'NGN',
    ): array {
        $response = $this->send('post', '/transaction/initialize', [
            'email' => $email,
            'amount' => $amountKobo,
            'currency' => $currency,
            'reference' => $reference,
            'callback_url' => $callbackUrl,
            'channels' => $channels,
            'metadata' => $metadata,
        ]);

        $data = $response->json('data') ?? [];

        if (! $response->json('status') || empty($data['authorization_url'])) {
            Log::error('paystack.initialize.rejected', ['reference' => $reference, 'message' => $response->json('message')]);
            throw new ExternalServiceException('paystack', 'We could not start the payment. Please try again.');
        }

        return [
            'authorization_url' => (string) $data['authorization_url'],
            'access_code' => (string) ($data['access_code'] ?? ''),
            'reference' => (string) ($data['reference'] ?? $reference),
        ];
    }

    /**
     * Authoritative transaction state from Paystack. Returns null when Paystack does not know the reference.
     *
     * @return array<string, mixed>|null
     */
    public function verifyTransaction(string $reference): ?array
    {
        $response = $this->send('get', '/transaction/verify/'.rawurlencode($reference), allowNotFound: true);

        if ($response->status() === 404 || $response->json('status') === false) {
            return null;
        }

        return $response->json('data');
    }

    /** @return list<array{code: string, name: string}> */
    public function listBanks(): array
    {
        return Cache::remember('paystack.banks.ng', now()->addDay(), function () {
            $response = $this->send('get', '/bank', ['country' => 'nigeria', 'perPage' => 100, 'use_cursor' => 'false']);

            return collect($response->json('data') ?? [])
                ->filter(fn ($b) => ($b['active'] ?? true) && ! ($b['is_deleted'] ?? false) && filled($b['code'] ?? null))
                ->map(fn ($b) => ['code' => (string) $b['code'], 'name' => (string) $b['name']])
                ->unique('code')
                ->sortBy('name')
                ->values()
                ->all();
        });
    }

    /** @return array{account_name: string, account_number: string}|null null when the account can't be resolved */
    public function resolveAccount(string $accountNumber, string $bankCode): ?array
    {
        $response = $this->send('get', '/bank/resolve', [
            'account_number' => $accountNumber,
            'bank_code' => $bankCode,
        ], allowClientErrors: true);

        if ($response->failed() || ! $response->json('status')) {
            return null;
        }

        return [
            'account_name' => (string) $response->json('data.account_name'),
            'account_number' => (string) $response->json('data.account_number'),
        ];
    }

    public function createTransferRecipient(string $name, string $accountNumber, string $bankCode): string
    {
        $response = $this->send('post', '/transferrecipient', [
            'type' => 'nuban',
            'name' => $name,
            'account_number' => $accountNumber,
            'bank_code' => $bankCode,
            'currency' => 'NGN',
        ]);

        $code = $response->json('data.recipient_code');

        if (! $code) {
            throw new ExternalServiceException('paystack', 'We could not register this payout account. Please try again.');
        }

        return (string) $code;
    }

    /** HMAC-SHA512 of the raw body with the secret key, compared in constant time. */
    public function isValidSignature(string $payload, ?string $signature): bool
    {
        if (! $this->isConfigured() || ! is_string($signature) || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha512', $payload, (string) $this->config['secret_key']), $signature);
    }

    // ------------------------------------------------------------------ internals

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->config['base_url'] ?? 'https://api.paystack.co', '/'))
            ->withToken((string) $this->config['secret_key'])
            ->acceptJson()
            ->timeout($this->config['timeout'] ?? 30)
            ->retry(2, 300, fn ($e) => $e instanceof ConnectionException, throw: false);
    }

    private function send(string $method, string $path, array $data = [], bool $allowNotFound = false, bool $allowClientErrors = false): Response
    {
        if (! $this->isConfigured()) {
            Log::error('paystack.not_configured');
            throw new ExternalServiceException('paystack', 'Payments are not available right now.');
        }

        try {
            $response = $this->client()->{$method}($path, $data);
        } catch (ConnectionException $e) {
            Log::error('paystack.connection_failed', ['path' => $this->safePath($path), 'error' => $e->getMessage()]);
            throw new ExternalServiceException('paystack', 'The payment service is temporarily unavailable. Please try again.');
        }

        $clientError = $response->clientError();

        if ($response->serverError() || ($clientError && ! $allowClientErrors && ! ($allowNotFound && $response->status() === 404) && $response->status() !== 400)) {
            Log::error('paystack.request_failed', [
                'path' => $this->safePath($path),
                'status' => $response->status(),
                'message' => $response->json('message'),
            ]);
            throw new ExternalServiceException('paystack', 'The payment service is temporarily unavailable. Please try again.');
        }

        return $response;
    }

    private function safePath(string $path): string
    {
        return preg_replace('/\d{6,}/', '[redacted]', $path) ?? $path;
    }
}
