<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Enums\VerificationPurpose;
use App\Exceptions\ApiException;
use App\Models\LoginActivity;
use App\Models\NotificationPreference;
use App\Models\PayoutAccount;
use App\Models\User;
use App\Notifications\VerificationCodeNotification;
use App\Services\Auth\AuthService;
use App\Services\Auth\VerificationCodeService;
use App\Services\Payments\PaystackService;
use App\Services\Sms\SmsSender;
use App\Support\Format;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Customer account settings: payout accounts, notification preferences, sessions,
 * phone change and account deletion (contract §5.8–§5.12).
 */
class AccountService
{
    public function __construct(
        private readonly PaystackService $paystack,
        private readonly VerificationCodeService $codes,
        private readonly SmsSender $sms,
        private readonly AuthService $auth,
        private readonly Notifier $notifier,
        private readonly LookupService $lookups,
    ) {}

    // ------------------------------------------------------------------ payout accounts

    /** @return array{account_name: string, account_number: string, bank_code: string} */
    public function resolveAccount(string $bankCode, string $accountNumber): array
    {
        $resolved = $this->paystack->resolveAccount($accountNumber, $bankCode);

        if ($resolved === null || $resolved['account_name'] === '') {
            throw ApiException::field('account_number', "We couldn't verify this account.");
        }

        return ['account_name' => $resolved['account_name'], 'account_number' => $accountNumber, 'bank_code' => $bankCode];
    }

    public function addPayoutAccount(User $user, string $bankCode, string $accountNumber): PayoutAccount
    {
        $resolved = $this->resolveAccount($bankCode, $accountNumber);
        $bankName = $this->lookups->bankName($bankCode) ?? 'Bank '.$bankCode;

        $existing = $user->payoutAccounts()->get()->first(fn (PayoutAccount $a) => $a->bank_code === $bankCode && $a->account_number === $accountNumber);
        if ($existing) {
            throw ApiException::field('account_number', 'This account has already been added.');
        }

        $recipientCode = $this->paystack->createTransferRecipient($resolved['account_name'], $accountNumber, $bankCode);

        $account = DB::transaction(function () use ($user, $bankCode, $bankName, $accountNumber, $resolved, $recipientCode) {
            $isFirst = ! $user->payoutAccounts()->exists();

            $account = new PayoutAccount([
                'bank_code' => $bankCode,
                'bank_name' => $bankName,
                'account_number' => $accountNumber,
                'account_last4' => substr($accountNumber, -4),
                'account_name' => $resolved['account_name'],
                'recipient_code' => $recipientCode,
                'is_default' => $isFirst,
            ]);
            $user->payoutAccounts()->save($account);

            return $account;
        });

        $this->notifier->user($user, 'payout_account_added', [
            'bank_name' => $bankName,
            'account_masked' => $account->maskedNumber(),
            'account_name' => $account->account_name,
        ]);

        return $account;
    }

    public function removePayoutAccount(User $user, PayoutAccount $account): void
    {
        DB::transaction(function () use ($user, $account) {
            $wasDefault = $account->is_default;
            $account->forceFill(['is_default' => false])->save();
            $account->delete();

            if ($wasDefault) {
                $user->payoutAccounts()->oldest()->first()?->forceFill(['is_default' => true])->save();
            }
        });

        $this->notifier->user($user, 'payout_account_removed', [
            'bank_name' => $account->bank_name,
            'account_masked' => $account->maskedNumber(),
        ]);
    }

    // ------------------------------------------------------------------ notification preferences

    /** @return list<array<string, mixed>> */
    public function preferences(User $user): array
    {
        $stored = $user->notificationPreferences()->pluck('enabled', 'key');

        return collect(config('amcom.notification_preferences'))
            ->map(fn (array $def, string $key) => [
                'key' => $key,
                'label' => $def['label'],
                'description' => $def['description'],
                'group' => $def['group'],
                'enabled' => $def['locked'] ? true : (bool) ($stored[$key] ?? $def['default']),
                'locked' => (bool) $def['locked'],
            ])
            ->values()
            ->all();
    }

    /** @param array<string, bool> $preferences */
    public function updatePreferences(User $user, array $preferences): array
    {
        $definitions = config('amcom.notification_preferences');

        DB::transaction(function () use ($user, $preferences, $definitions) {
            foreach ($preferences as $key => $enabled) {
                if (! isset($definitions[$key]) || $definitions[$key]['locked']) {
                    continue; // unknown or locked keys are ignored (contract §5.9)
                }

                NotificationPreference::query()->updateOrCreate(
                    ['user_id' => $user->id, 'key' => $key],
                    ['enabled' => (bool) $enabled],
                );
            }
        });

        return $this->preferences($user);
    }

    // ------------------------------------------------------------------ sessions

    public function sessions(User $user): Collection
    {
        return $user->loginActivities()->latest('id')->limit((int) config('amcom.auth.max_login_activities'))->get();
    }

    public function revokeSession(User $user, LoginActivity $activity): void
    {
        DB::transaction(function () use ($user, $activity) {
            if ($activity->token_id) {
                PersonalAccessToken::query()
                    ->where('tokenable_type', $user->getMorphClass())
                    ->where('tokenable_id', $user->id)
                    ->whereKey($activity->token_id)
                    ->delete();
            }

            $activity->forceFill(['signed_out_at' => $activity->signed_out_at ?? now()])->save();
        });
    }

    // ------------------------------------------------------------------ phone change

    /** @return array{masked_destination: string} */
    public function requestPhoneChange(User $user, string $phone): array
    {
        if (User::query()->where('phone', $phone)->whereKeyNot($user->id)->exists()) {
            throw ApiException::field('phone', 'This phone number is already in use.');
        }

        $code = $this->codes->issue(VerificationPurpose::PhoneChange, $user, null, ['phone' => $phone]);

        if (config('services.sms.driver') !== 'log') {
            $this->sms->send($phone, "Your Amcom verification code is {$code}. It expires in {$this->codes->ttl()} minutes.");

            return ['masked_destination' => Format::maskPhone($phone)];
        }

        // Without an SMS provider the code goes to the verified account email.
        $user->notify(new VerificationCodeNotification(VerificationPurpose::PhoneChange, $code, $this->codes->ttl()));

        return ['masked_destination' => Format::maskEmail($user->email)];
    }

    public function confirmPhoneChange(User $user, string $phone, string $code): User
    {
        $record = $this->codes->consume(VerificationPurpose::PhoneChange, $code, $user);

        if ($record === null || ($record->meta['phone'] ?? null) !== $phone) {
            throw ApiException::field('code', 'The code is invalid or has expired.');
        }

        $user->forceFill(['phone' => $phone])->save();

        $this->notifier->user($user, 'phone_changed', ['phone_masked' => Format::maskPhone($phone)]);

        return $user->fresh(['profile', 'latestIdentityVerification']);
    }

    // ------------------------------------------------------------------ deletion

    /**
     * Schedules deletion: the account is closed now and anonymised later by `accounts:anonymise`
     * (financial records are retained in anonymised form).
     */
    public function scheduleDeletion(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user->forceFill([
                'status' => UserStatus::PendingDeletion,
                'deletion_scheduled_at' => now(),
            ])->save();
        });

        $this->notifier->user($user, 'account_deletion_scheduled');
        $this->auth->revokeAllTokens($user);
    }
}
