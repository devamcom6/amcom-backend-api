<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\CheckMaintenanceMode;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\EnsureUserIsStaff;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\RequirePermission;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '127.0.0.1'));

        $middleware->api(prepend: [ForceJsonResponse::class], append: [CheckMaintenanceMode::class]);

        $middleware->alias([
            'active' => EnsureAccountIsActive::class,
            'verified.email' => EnsureEmailIsVerified::class,
            'staff' => EnsureUserIsStaff::class,
            'permission' => RequirePermission::class,
        ]);

        // Never redirect API clients to a login page.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('buyback:release-reservations')->everyMinute()->withoutOverlapping();
        $schedule->command('investments:mature')->dailyAt('00:10')->onOneServer();
        $schedule->command('notifications:maturity-reminders')->dailyAt('08:00')->onOneServer();
        $schedule->command('notifications:renewal-reminders')->dailyAt('08:30')->onOneServer();
        $schedule->command('notifications:roi-updates')->monthlyOn(1, '09:00')->onOneServer();
        $schedule->command('inspections:send-reminders')->hourly()->onOneServer();
        $schedule->command('accounts:anonymise')->dailyAt('02:00')->onOneServer();
        $schedule->command('sanctum:prune-expired --hours=24')->daily();
        $schedule->command('queue:prune-failed --hours=720')->daily();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([ApiException::class]);

        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        // Every API error uses the contract envelope (§1.4) with a stable `code` (§1.6).
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return match (true) {
                $e instanceof ApiException => ApiResponse::error($e->getMessage(), $e->errorCode, $e->status, $e->errors, $e->headers),

                $e instanceof ValidationException => ApiResponse::error('Validation failed.', 'validation_failed', 422, $e->errors()),

                $e instanceof AuthenticationException => (function () use ($request) {
                    $token = $request->bearerToken() ? PersonalAccessToken::findToken($request->bearerToken()) : null;
                    $expired = $token?->expires_at?->isPast() ?? false;

                    return $expired
                        ? ApiResponse::error('Your session has expired. Please sign in again.', 'token_expired', 401)
                        : ApiResponse::error('Please sign in to continue.', 'unauthenticated', 401);
                })(),

                $e instanceof TokenMismatchException => ApiResponse::error('Your session has expired. Please refresh and try again.', 'csrf_mismatch', 419),

                $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => ApiResponse::error('This action is unauthorized.', 'forbidden', 403),

                $e instanceof InvalidSignatureException => ApiResponse::error('This link is invalid or has expired.', 'invalid_signature', 403),

                $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => ApiResponse::error('The requested resource was not found.', 'not_found', 404),

                $e instanceof MethodNotAllowedHttpException => ApiResponse::error('This method is not supported for this endpoint.', 'method_not_allowed', 405),

                $e instanceof ThrottleRequestsException => ApiResponse::error(
                    'Too many attempts. Please try again in '.($e->getHeaders()['Retry-After'] ?? 60).' seconds.',
                    'too_many_requests',
                    429,
                    headers: $e->getHeaders(),
                ),

                $e instanceof PostTooLargeException => ApiResponse::error('The uploaded file is too large.', 'payload_too_large', 413),

                $e instanceof HttpExceptionInterface && $e->getStatusCode() === 503 => ApiResponse::error(
                    'Amcom is undergoing scheduled maintenance. Please check back shortly.', 'maintenance', 503, headers: $e->getHeaders(),
                ),

                $e instanceof HttpExceptionInterface => ApiResponse::error(
                    $e->getStatusCode() < 500 ? 'The request could not be completed.' : 'Something went wrong. Please try again.',
                    'http_error',
                    $e->getStatusCode(),
                    headers: $e->getHeaders(),
                ),

                // Never leak exception text, SQL, paths or traces (contract §1.4).
                default => ApiResponse::error('Something went wrong. Please try again.', 'server_error', 500),
            };
        });
    })->create();
