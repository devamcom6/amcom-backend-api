<?php

namespace App\Services\Identity;

use App\Enums\IdType;
use App\Enums\KycStatus;
use App\Models\User;

/**
 * Local/testing driver (IDENTITY_DRIVER=fake). Any well-formed number verifies, except
 * numbers ending in "0000" (fail) and "9999" (pending). Never enable in production.
 */
class FakeIdentityVerifier implements IdentityVerifier
{
    public function name(): string
    {
        return 'fake';
    }

    public function verify(User $user, IdType $idType, string $idNumber): IdentityResult
    {
        if (str_ends_with($idNumber, '0000')) {
            return new IdentityResult(KycStatus::Failed, failureReason: 'no_match');
        }

        if (str_ends_with($idNumber, '9999')) {
            return new IdentityResult(KycStatus::Pending, providerReference: 'fake_'.bin2hex(random_bytes(6)));
        }

        return new IdentityResult(
            KycStatus::Verified,
            providerReference: 'fake_'.bin2hex(random_bytes(6)),
            firstName: $user->first_name ?: 'Adaeze',
            lastName: $user->last_name ?: 'Okonkwo',
            dateOfBirth: $user->profile?->date_of_birth?->toDateString() ?: '1992-05-04',
            gender: $user->profile?->gender ?: 'female',
            nationality: 'Nigerian',
        );
    }
}
