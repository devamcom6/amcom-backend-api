<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A business-rule failure with a stable, frontend-facing error code (contract §1.6).
 * `message` must be safe to show to end users.
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<string, string>  $headers
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 422,
        public readonly array $errors = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    /** 422 with an error attached to a request field (rendered under that field). */
    public static function field(string $field, string $message, string $code = 'validation_failed'): self
    {
        return new self($message, $code, 422, [$field => [$message]]);
    }

    public static function conflict(string $message, string $code = 'conflict'): self
    {
        return new self($message, $code, 409);
    }

    public static function forbidden(string $message = 'This action is unauthorized.', string $code = 'forbidden'): self
    {
        return new self($message, $code, 403);
    }

    public static function notFound(string $message = 'The requested resource was not found.', string $code = 'not_found'): self
    {
        return new self($message, $code, 404);
    }

    public static function unavailable(string $message, string $code = 'provider_unavailable'): self
    {
        return new self($message, $code, 503);
    }
}
