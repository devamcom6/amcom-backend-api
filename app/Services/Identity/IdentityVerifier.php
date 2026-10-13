<?php

namespace App\Services\Identity;

use App\Enums\IdType;
use App\Models\User;

interface IdentityVerifier
{
    public function name(): string;

    /** Verifies a government ID server-side. Never persist the full ID number. */
    public function verify(User $user, IdType $idType, string $idNumber): IdentityResult;
}
