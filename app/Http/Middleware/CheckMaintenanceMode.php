<?php

namespace App\Http\Middleware;

use App\Services\SettingsService;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When the `general.maintenance_mode` setting is on, customer-facing endpoints answer
 * 503 `maintenance`. Auth, admin and webhook routes stay available so staff can switch it off.
 */
class CheckMaintenanceMode
{
    private const EXEMPT = ['api/v1/admin/*', 'api/v1/auth/*', 'api/v1/webhooks/*', 'api/v1/documents/*'];

    public function __construct(private readonly SettingsService $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->settings->maintenanceMode() && ! $request->is(...self::EXEMPT)) {
            return ApiResponse::error(
                'Amcom is undergoing scheduled maintenance. Please check back shortly.',
                'maintenance',
                503,
                headers: ['Retry-After' => '300'],
            );
        }

        return $next($request);
    }
}
