<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() !== null && ! $request->user()->hasVerifiedEmail()) {
            throw ApiException::forbidden('Please verify your email address to continue.', 'email_unverified');
        }

        return $next($request);
    }
}
