<?php

namespace App\Services\Sms;

use App\Exceptions\ExternalServiceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Termii (Nigeria) SMS driver: SMS_DRIVER=termii. */
class TermiiSmsSender implements SmsSender
{
    public function __construct(private readonly array $config) {}

    public function send(string $phone, string $message): void
    {
        try {
            $response = Http::acceptJson()->timeout(20)->post(rtrim($this->config['base_url'], '/').'/sms/send', [
                'api_key' => $this->config['api_key'],
                'to' => ltrim($phone, '+'),
                'from' => $this->config['sender_id'],
                'sms' => $message,
                'type' => 'plain',
                'channel' => 'generic',
            ]);
        } catch (ConnectionException $e) {
            Log::error('sms.termii.connection_failed', ['error' => $e->getMessage()]);
            throw new ExternalServiceException('termii', 'We could not send the SMS. Please try again.');
        }

        if ($response->failed()) {
            Log::error('sms.termii.failed', ['status' => $response->status(), 'message' => $response->json('message')]);
            throw new ExternalServiceException('termii', 'We could not send the SMS. Please try again.');
        }
    }
}
