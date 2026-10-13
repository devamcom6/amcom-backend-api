<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/** Every /admin/* route requires role admin or super_admin (role read from the DB, never the client). */
class EnsureUserIsStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isStaff()) {
            Log::notice('authz.admin_denied', ['user_id' => $request->user()?->id, 'path' => $request->path()]);
            throw ApiException::forbidden();
        }

        return $next($request);
    }
}
