<?php

namespace App\Services\Identity;

use App\Enums\KycStatus;

final class IdentityResult
{
    public function __construct(
        public readonly KycStatus $status,
        public readonly ?string $providerReference = null,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $dateOfBirth = null,
        public readonly ?string $gender = null,
        public readonly ?string $nationality = null,
        public readonly ?string $failureReason = null,
    ) {}
}
