<?php

namespace App\Services\Auth;

use App\Enums\VerificationPurpose;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Notifications\VerificationCodeNotification;
use App\Services\Notifier;
use App\Services\Sms\SmsSender;
use App\Support\Format;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Code-based two-factor authentication (SMS when configured, email otherwise) with recovery codes.
 */
class TwoFactorService
{
    public function __construct(
        private readonly VerificationCodeService $codes,
        private readonly SmsSender $sms,
        private readonly Notifier $notifier,
    ) {}

    /** @return array{two_factor_required: true, channel: string, masked_destination: string} */
    public function sendChallenge(User $user): array
    {
        return ['two_factor_required' => true] + $this->deliver($user);
    }

    public function verifyLoginCode(User $user, string $code): bool
    {
        if ($this->codes->consume(VerificationPurpose::TwoFactor, $code, $user)) {
            return true;
        }

        return $this->useRecoveryCode($user, $code);
    }

    /** Starts enrolment by sending a confirmation code. */
    public function enable(User $user): array
    {
        return $this->deliver($user);
    }

    /** @return list<string> recovery codes (shown once) */
    public function confirm(User $user, string $code): array
    {
        if (! $this->codes->consume(VerificationPurpose::TwoFactor, $code, $user)) {
            throw ApiException::field('code', 'The code is invalid or has expired.');
        }

        $recoveryCodes = collect(range(1, 8))
            ->map(fn () => strtoupper(Str::random(4).'-'.Str::random(4)))
            ->all();

        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_recovery_codes' => array_map(fn ($c) => hash('sha256', $c), $recoveryCodes),
        ])->save();

        $this->notifier->user($user, 'two_factor_enabled');

        return $recoveryCodes;
    }

    public function disable(User $user, string $password): void
    {
        if (! Hash::check($password, $user->password)) {
            throw ApiException::field('password', 'The password is incorrect.');
        }

        $user->forceFill(['two_factor_enabled' => false, 'two_factor_recovery_codes' => null])->save();

        $this->notifier->user($user, 'two_factor_disabled');
    }

    /** @return array{channel: string, masked_destination: string} */
    private function deliver(User $user): array
    {
        $code = $this->codes->issue(VerificationPurpose::TwoFactor, $user);
        $useSms = config('amcom.auth.two_factor_channel') === 'sms' && filled($user->phone);

        if ($useSms) {
            $this->sms->send($user->phone, "Your Amcom code is {$code}. It expires in {$this->codes->ttl()} minutes.");

            return ['channel' => 'sms', 'masked_destination' => Format::maskPhone($user->phone)];
        }

        $user->notify(new VerificationCodeNotification(VerificationPurpose::TwoFactor, $code, $this->codes->ttl()));

        return ['channel' => 'email', 'masked_destination' => Format::maskEmail($user->email)];
    }

    private function useRecoveryCode(User $user, string $code): bool
    {
        $hashes = $user->two_factor_recovery_codes ?? [];
        $hash = hash('sha256', strtoupper(trim($code)));

        if (! in_array($hash, $hashes, true)) {
            return false;
        }

        $user->forceFill(['two_factor_recovery_codes' => array_values(array_diff($hashes, [$hash]))])->save();

        return true;
    }
}
