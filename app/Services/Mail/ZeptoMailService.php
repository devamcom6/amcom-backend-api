<?php

namespace App\Services\Mail;

use App\Exceptions\ExternalServiceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin client for the Zoho ZeptoMail "send email" API.
 * Application code never calls this directly — it sends Laravel mail/notifications,
 * and the `zeptomail` mail transport (ZeptoMailTransport) delegates here.
 */
class ZeptoMailService
{
    public function __construct(private readonly array $config) {}

    public function isConfigured(): bool
    {
        return filled($this->config['api_key'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $payload  ZeptoMail v1.1 payload (from, to, subject, htmlbody, …)
     * @return string|null provider request id
     */
    public function send(array $payload): ?string
    {
        if (! $this->isConfigured()) {
            throw new ExternalServiceException('zeptomail', 'Email delivery is not configured.');
        }

        if (empty($payload['bounce_address']) && filled($this->config['bounce_address'] ?? null)) {
            $payload['bounce_address'] = $this->config['bounce_address'];
        }

        try {
            $response = Http::withHeaders(['Authorization' => $this->authorizationHeader()])
                ->acceptJson()
                ->asJson()
                ->timeout($this->config['timeout'] ?? 30)
                ->post($this->config['endpoint'], $payload);
        } catch (ConnectionException $e) {
            Log::error('zeptomail.connection_failed', ['error' => $e->getMessage()]);
            throw new ExternalServiceException('zeptomail', 'Email delivery is temporarily unavailable.');
        }

        if ($response->failed()) {
            Log::error('zeptomail.send_failed', [
                'status' => $response->status(),
                'error_code' => $response->json('error.code'),
                'error' => $response->json('error.message'),
                'subject' => $payload['subject'] ?? null,
            ]);
            throw new ExternalServiceException('zeptomail', 'Email delivery failed.');
        }

        return $response->json('request_id');
    }

    /** @return array{address: string, name: string|null} */
    public function defaultFrom(): array
    {
        return ['address' => (string) $this->config['from_address'], 'name' => $this->config['from_name'] ?? null];
    }

    private function authorizationHeader(): string
    {
        $key = trim((string) $this->config['api_key']);

        // The dashboard shows the token either bare or prefixed; accept both.
        return str_starts_with($key, 'Zoho-enczapikey') ? $key : 'Zoho-enczapikey '.$key;
    }
}
