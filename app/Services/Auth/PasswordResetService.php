<?php

namespace App\Services\Auth;

use App\Enums\UserStatus;
use App\Enums\VerificationPurpose;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Notifications\VerificationCodeNotification;
use App\Services\Notifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Password reset: email → 6-digit code → short-lived reset_token → new password.
 * Never reveals whether an account exists. Also used to set the password of invited admins.
 */
class PasswordResetService
{
    public function __construct(
        private readonly VerificationCodeService $codes,
        private readonly AuthService $auth,
        private readonly Notifier $notifier,
    ) {}

    public function sendCode(string $email): void
    {
        $user = User::query()->where('email', mb_strtolower($email))->first();

        if ($user === null || in_array($user->status, [UserStatus::Suspended, UserStatus::PendingDeletion], true)) {
            return; // same response either way
        }

        $code = $this->codes->issue(VerificationPurpose::PasswordReset, $user);
        $user->notify(new VerificationCodeNotification(VerificationPurpose::PasswordReset, $code, $this->codes->ttl()));
    }

    /** @return array{reset_token: string, expires_at: Carbon} */
    public function verifyCode(string $email, string $code): array
    {
        $user = User::query()->where('email', mb_strtolower($email))->first();

        if ($user === null || ! $this->codes->consume(VerificationPurpose::PasswordReset, $code, $user)) {
            throw ApiException::field('code', 'The code is invalid or has expired.');
        }

        return $this->createResetToken($user, (int) config('amcom.auth.reset_token_ttl_minutes'));
    }

    /** @return array{reset_token: string, expires_at: Carbon} */
    public function createResetToken(User $user, int $ttlMinutes): array
    {
        $token = Str::random(64);
        $expiresAt = now()->addMinutes($ttlMinutes);

        // The `created_at` column holds the token's expiry time, so reset (15 min) and invite (72 h)
        // tokens with different TTLs share one check in reset().
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => Hash::make($token), 'created_at' => $expiresAt],
        );

        return ['reset_token' => $token, 'expires_at' => $expiresAt];
    }

    public function reset(string $email, string $token, string $password): User
    {
        $email = mb_strtolower($email);
        $row = DB::table('password_reset_tokens')->where('email', $email)->first();
        $user = User::query()->where('email', $email)->first();

        $valid = $row !== null && $user !== null
            && Carbon::parse($row->created_at)->isFuture()
            && Hash::check($token, $row->token);

        if (! $valid) {
            throw new ApiException(
                'This reset link is invalid or has expired. Please request a new code.',
                'reset_token_invalid',
                422,
                ['reset_token' => ['This reset link is invalid or has expired. Please request a new code.']],
            );
        }

        if ($user->status !== UserStatus::Invited && Hash::check($password, $user->password)) {
            throw ApiException::field('password', 'Your new password must be different from your previous password.');
        }

        DB::transaction(function () use ($user, $password, $email) {
            $attributes = ['password' => $password, 'password_changed_at' => now()];

            if ($user->status === UserStatus::Invited) {
                $attributes['status'] = UserStatus::Active;
                $attributes['email_verified_at'] = $user->email_verified_at ?? now();
            }

            $user->forceFill($attributes)->save();
            DB::table('password_reset_tokens')->where('email', $email)->delete();
        });

        $this->auth->revokeAllTokens($user);
        $this->notifier->user($user, 'password_changed', ['time' => now()->format('M j, Y g:i A')]);

        return $user;
    }
}
