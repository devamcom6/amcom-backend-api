<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuthUserResource;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Auth\AuthService;
use App\Services\Notifier;
use App\Support\ApiResponse;
use App\Support\Format;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Throwable;

/**
 * Google sign-in (contract §4.11). The OAuth redirect URI is the FRONTEND callback route;
 * the frontend posts Google's `code` + `state` back here for the token exchange.
 */
class GoogleAuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly Notifier $notifier,
    ) {}

    public function redirect(Request $request): JsonResponse
    {
        $this->ensureEnabled();

        $redirectTo = $this->safePath($request->query('redirect_to'));
        $state = Crypt::encryptString(json_encode(['r' => $redirectTo, 'n' => Str::random(16), 'e' => now()->addMinutes(10)->timestamp]));

        $url = $this->google()
            ->stateless()
            ->redirectUrl(config('services.google.redirect'))
            ->scopes(['openid', 'email', 'profile'])
            ->with(['state' => $state, 'prompt' => 'select_account'])
            ->redirect()
            ->getTargetUrl();

        return ApiResponse::success(['url' => $url]);
    }

    public function callback(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $request->validate(['code' => ['required', 'string', 'max:2048'], 'state' => ['required', 'string', 'max:4096']]);

        $redirectTo = $this->readState($request->input('state'));

        try {
            $googleUser = $this->google()->stateless()->redirectUrl(config('services.google.redirect'))->user();
        } catch (Throwable $e) {
            Log::warning('auth.google.exchange_failed', ['error' => $e->getMessage()]);
            throw new ApiException('Google sign-in failed. Please try again.', 'invalid_credentials', 401);
        }

        $email = mb_strtolower((string) $googleUser->getEmail());

        if ($email === '' || ! ($googleUser->user['email_verified'] ?? true)) {
            throw new ApiException('Your Google account email is not verified.', 'invalid_credentials', 401);
        }

        $user = User::query()->where('google_id', $googleUser->getId())->first()
            ?? User::query()->where('email', $email)->first();

        $isNew = $user === null;

        if ($isNew) {
            $user = DB::transaction(function () use ($googleUser, $email) {
                $name = trim((string) $googleUser->getName());
                [$first, $last] = array_pad(explode(' ', $name, 2), 2, null);

                $user = new User(['first_name' => $first ?: null, 'last_name' => $last, 'email' => $email, 'password' => Str::random(40)]);
                $user->forceFill([
                    'role' => Role::User->value,
                    'status' => UserStatus::Active,
                    'google_id' => $googleUser->getId(),
                    'email_verified_at' => now(),
                ])->save();
                $user->profile()->save(new UserProfile);

                return $user;
            });

            $this->notifier->user($user, 'welcome');
        } else {
            if (in_array($user->status, [UserStatus::Suspended], true)) {
                throw ApiException::forbidden('Your account has been suspended. Please contact support.', 'account_suspended');
            }

            if ($user->status !== UserStatus::Active) {
                throw new ApiException('These credentials do not match our records.', 'invalid_credentials', 401);
            }

            $user->forceFill([
                'google_id' => $user->google_id ?? $googleUser->getId(),
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();
        }

        $session = $this->auth->issueSession($user, false, $request, $isNew);

        return ApiResponse::success([
            'user' => (new AuthUserResource($session['user']))->resolve($request),
            'token' => $session['token'],
            'token_type' => 'Bearer',
            'expires_at' => Format::iso($session['expires_at']),
            'redirect_to' => $redirectTo,
            'is_new_user' => $isNew,
        ], 'Login successful.');
    }

    private function google(): AbstractProvider
    {
        $driver = Socialite::driver('google');

        if (! $driver instanceof AbstractProvider) {
            throw ApiException::unavailable('Google sign-in is not available right now.');
        }

        return $driver;
    }

    private function ensureEnabled(): void
    {
        if (blank(config('services.google.client_id')) || blank(config('services.google.client_secret'))) {
            throw ApiException::unavailable('Google sign-in is not available right now.');
        }
    }

    private function readState(string $state): ?string
    {
        try {
            $payload = json_decode(Crypt::decryptString($state), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw new ApiException('Google sign-in expired. Please try again.', 'invalid_credentials', 401);
        }

        if (($payload['e'] ?? 0) < now()->timestamp) {
            throw new ApiException('Google sign-in expired. Please try again.', 'invalid_credentials', 401);
        }

        return $payload['r'] ?? null;
    }

    /** Same-origin relative paths only (prevents open redirects). */
    private function safePath(mixed $path): ?string
    {
        return is_string($path) && str_starts_with($path, '/') && ! str_starts_with($path, '//') && ! str_contains($path, '\\')
            ? mb_substr($path, 0, 500)
            : null;
    }
}
