<?php

namespace App\Exceptions;

/**
 * Raised when a third-party provider (Cloudinary, Paystack, ZeptoMail, Persona…) fails.
 * The user-facing message is generic; provider detail goes to the log only.
 */
class ExternalServiceException extends ApiException
{
    public function __construct(
        public readonly string $provider,
        string $message = 'A service we depend on is temporarily unavailable. Please try again shortly.',
        int $status = 503,
        string $code = 'provider_unavailable',
        array $errors = [],
    ) {
        parent::__construct($message, $code, $status, $errors);
    }
}
