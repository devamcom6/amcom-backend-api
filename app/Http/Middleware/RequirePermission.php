<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('permission:users.manage'). Several keys (comma-separated) require ALL of them.
 * Permissions are resolved from the database on each request, so revocations apply immediately.
 */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        foreach ($permissions as $permission) {
            if (! $user?->hasPermission($permission)) {
                Log::notice('authz.permission_denied', ['user_id' => $user?->id, 'permission' => $permission, 'path' => $request->path()]);
                throw ApiException::forbidden();
            }
        }

        return $next($request);
    }
}
