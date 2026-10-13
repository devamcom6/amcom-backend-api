<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Suspended accounts get 403 `account_suspended` on every authenticated request (contract §1.3). */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->status !== UserStatus::Active) {
            if ($user->status === UserStatus::Suspended) {
                throw ApiException::forbidden('Your account has been suspended. Please contact support.', 'account_suspended');
            }

            throw new ApiException('Your session has expired. Please sign in again.', 'unauthenticated', 401);
        }

        return $next($request);
    }
}
