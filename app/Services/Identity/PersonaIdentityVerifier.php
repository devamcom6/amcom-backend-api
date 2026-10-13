<?php

namespace App\Services\Identity;

use App\Enums\IdType;
use App\Enums\KycStatus;
use App\Exceptions\ExternalServiceException;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Persona driver (IDENTITY_DRIVER=persona). Creates an inquiry from the configured template with
 * the submitted ID. Persona completes the check asynchronously, so the result is `pending`
 * until a Persona webhook settles it (webhook not yet wired; see docs/API_IMPLEMENTATION_STATUS.md).
 */
class PersonaIdentityVerifier implements IdentityVerifier
{
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'persona';
    }

    public function verify(User $user, IdType $idType, string $idNumber): IdentityResult
    {
        if (blank($this->config['api_key'] ?? null) || blank($this->config['inquiry_template_id'] ?? null)) {
            throw new ExternalServiceException('persona', 'Identity verification is not available right now.');
        }

        try {
            $response = Http::withToken($this->config['api_key'])
                ->acceptJson()
                ->timeout($this->config['timeout'] ?? 30)
                ->post(rtrim($this->config['base_url'], '/').'/inquiries', [
                    'data' => [
                        'attributes' => [
                            'inquiry-template-id' => $this->config['inquiry_template_id'],
                            'reference-id' => 'user-'.$user->id,
                            'fields' => [
                                'identification-class' => $idType->value,
                                'identification-number' => $idNumber,
                                'name-first' => $user->first_name,
                                'name-last' => $user->last_name,
                                'email-address' => $user->email,
                            ],
                        ],
                    ],
                ]);
        } catch (ConnectionException $e) {
            Log::error('persona.connection_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            throw new ExternalServiceException('persona', 'Identity verification is temporarily unavailable.');
        }

        if ($response->failed()) {
            Log::error('persona.inquiry_failed', ['user_id' => $user->id, 'status' => $response->status()]);

            if ($response->status() === 422) {
                return new IdentityResult(KycStatus::Failed, failureReason: 'rejected');
            }

            throw new ExternalServiceException('persona', 'Identity verification is temporarily unavailable.');
        }

        return new IdentityResult(KycStatus::Pending, providerReference: (string) $response->json('data.id'));
    }
}
