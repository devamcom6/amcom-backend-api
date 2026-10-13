<?php

namespace App\Http\Controllers;

use App\Enums\UserStatus;
use App\Models\User;

abstract class Controller
{
    /**
     * The signed-in user on public endpoints that accept an optional bearer token
     * (lead forms link submissions to the account). Inactive accounts are treated as guests.
     */
    protected function optionalUser(): ?User
    {
        $user = auth('sanctum')->user();

        return $user instanceof User && $user->status === UserStatus::Active ? $user : null;
    }
}
