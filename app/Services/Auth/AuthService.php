<?php

namespace App\Services\Auth;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Enums\VerificationPurpose;
use App\Exceptions\ApiException;
use App\Models\LoginActivity;
use App\Models\User;
use App\Models\UserProfile;
use App\Notifications\VerificationCodeNotification;
use App\Services\Notifier;
use App\Support\UserAgent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

class AuthService
{
    public function __construct(
        private readonly VerificationCodeService $codes,
        private readonly TwoFactorService $twoFactor,
        private readonly Notifier $notifier,
    ) {}

    /** @return array{user: User, token: string, expires_at: \DateTimeInterface|null} */
    public function register(string $email, string $password, Request $request): array
    {
        $user = DB::transaction(function () use ($email, $password) {
            $user = new User(['email' => mb_strtolower($email), 'password' => $password]);
            $user->forceFill(['role' => Role::User->value, 'status' => UserStatus::Active]);
            $user->save();

            $user->profile()->save(new UserProfile);

            return $user;
        });

        $this->sendEmailVerificationCode($user);
        $this->notifier->user($user, 'welcome');

        return $this->issueSession($user, false, $request, isNewAccount: true);
    }

    /**
     * Returns either a session or a 2FA challenge.
     *
     * @return array{type: 'session', session: array}|array{type: 'challenge', challenge: array}
     */
    public function login(string $email, string $password, bool $remember, ?string $twoFactorCode, Request $request): array
    {
        $user = User::query()->where('email', mb_strtolower($email))->first();

        // Same hashing work whether or not the user exists (mitigates enumeration by timing).
        if ($user === null) {
            Hash::check($password, self::dummyHash());
            $valid = false;
        } else {
            $valid = Hash::check($password, $user->password);
        }

        if (! $valid || in_array($user->status, [UserStatus::PendingDeletion, UserStatus::Invited], true)) {
            Log::notice('auth.login.failed', ['email_hash' => hash('sha256', mb_strtolower($email)), 'ip' => $request->ip()]);
            throw new ApiException('These credentials do not match our records.', 'invalid_credentials', 401);
        }

        if ($user->status === UserStatus::Suspended) {
            throw ApiException::forbidden('Your account has been suspended. Please contact support.', 'account_suspended');
        }

        if ($user->two_factor_enabled) {
            if ($twoFactorCode === null || $twoFactorCode === '') {
                return ['type' => 'challenge', 'challenge' => $this->twoFactor->sendChallenge($user)];
            }

            if (! $this->twoFactor->verifyLoginCode($user, $twoFactorCode)) {
                throw ApiException::field('two_factor_code', 'The two-factor code is invalid or has expired.');
            }
        }

        return ['type' => 'session', 'session' => $this->issueSession($user, $remember, $request)];
    }

    /** @return array{user: User, token: string, expires_at: \DateTimeInterface|null} */
    public function issueSession(User $user, bool $remember, Request $request, bool $isNewAccount = false): array
    {
        $minutes = $remember
            ? (int) config('amcom.auth.remember_token_ttl_minutes')
            : (int) config('amcom.auth.token_ttl_minutes');
        $expiresAt = now()->addMinutes($minutes);

        $device = UserAgent::describe($request->userAgent());
        $fingerprint = hash('sha256', $device.'|'.UserAgent::maskIp($request->ip()));

        $knownDevice = $user->loginActivities()->where('fingerprint', $fingerprint)->exists();
        $hasHistory = $user->loginActivities()->exists();

        $token = $user->createToken(mb_substr($device, 0, 100), ['*'], $expiresAt);

        LoginActivity::create([
            'user_id' => $user->id,
            'token_id' => $token->accessToken->id,
            'device' => $device,
            'location' => $this->location($request),
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
            'fingerprint' => $fingerprint,
        ]);

        $user->forceFill(['last_login_at' => now()])->save();

        if (! $isNewAccount && $hasHistory && ! $knownDevice) {
            $this->notifier->user($user, 'login_alert', [
                'device' => $device,
                'ip_address' => UserAgent::maskIp($request->ip()),
                'time' => now()->timezone(config('amcom.inspections.timezone'))->format('M j, Y g:i A T'),
            ]);
        }

        return ['user' => $user->fresh(), 'token' => $token->plainTextToken, 'expires_at' => $expiresAt];
    }

    public function logout(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            LoginActivity::query()->where('token_id', $token->id)->update(['signed_out_at' => now()]);
            $token->delete();
        }
    }

    public function revokeAllTokens(User $user): void
    {
        $tokenIds = $user->tokens()->pluck('id');
        LoginActivity::query()->whereIn('token_id', $tokenIds)->whereNull('signed_out_at')->update(['signed_out_at' => now()]);
        $user->tokens()->delete();
    }

    public function sendEmailVerificationCode(User $user): void
    {
        $code = $this->codes->issue(VerificationPurpose::EmailVerification, $user);
        $user->notify(new VerificationCodeNotification(VerificationPurpose::EmailVerification, $code, $this->codes->ttl()));
    }

    public function verifyEmail(User $user, string $code): User
    {
        if ($user->hasVerifiedEmail()) {
            return $user;
        }

        if (! $this->codes->consume(VerificationPurpose::EmailVerification, $code, $user)) {
            throw ApiException::field('code', 'The verification code is invalid or has expired.');
        }

        $user->forceFill(['email_verified_at' => now()])->save();

        return $user->fresh();
    }

    public function changePassword(User $user, string $current, string $new): void
    {
        if (! Hash::check($current, $user->password)) {
            throw ApiException::field('current_password', 'The current password is incorrect.');
        }

        if (Hash::check($new, $user->password)) {
            throw ApiException::field('password', 'Your new password must be different from your current password.');
        }

        $user->forceFill(['password' => $new, 'password_changed_at' => now()])->save();

        // Keep the current session; sign out every other device.
        $current = $user->currentAccessToken();
        $others = $user->tokens()->when($current instanceof PersonalAccessToken, fn ($q) => $q->whereKeyNot($current->id));
        LoginActivity::query()->whereIn('token_id', (clone $others)->pluck('id'))->update(['signed_out_at' => now()]);
        $others->delete();

        $this->notifier->user($user, 'password_changed', ['time' => now()->format('M j, Y g:i A')]);
    }

    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make('timing-equaliser');
    }

    private function location(Request $request): ?string
    {
        // Populated when running behind Cloudflare; no third-party GeoIP lookup is made.
        $country = $request->header('CF-IPCountry');
        $city = $request->header('CF-IPCity');

        return collect([$city, $country])->filter(fn ($v) => $v && $v !== 'XX')->implode(', ') ?: null;
    }
}
