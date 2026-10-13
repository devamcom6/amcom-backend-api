<?php

namespace App\Services;

use App\Enums\IdType;
use App\Enums\KycStatus;
use App\Enums\OnboardingStep;
use App\Exceptions\ApiException;
use App\Models\IdentityVerification;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Identity\IdentityVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Customer profile and the 4-step onboarding (identity → personal → contact → next of kin).
 */
class ProfileService
{
    private const STEP_ORDER = ['identity', 'personal', 'contact', 'next_of_kin', 'completed'];

    public function __construct(
        private readonly IdentityVerifier $verifier,
        private readonly Notifier $notifier,
    ) {}

    public function profileFor(User $user): UserProfile
    {
        return $user->profile ?? $user->profile()->create([]);
    }

    /** @return list<string> */
    public function lockedFields(User $user): array
    {
        return $user->latestIdentityVerification?->lockedFields() ?? [];
    }

    /** Settings → Profile (contract §5.2). */
    public function update(User $user, array $data): User
    {
        $this->assertNotChangingLocked($user, $data);

        DB::transaction(function () use ($user, $data) {
            $user->fill(array_intersect_key($data, array_flip(['first_name', 'last_name', 'phone'])))->save();
            $this->profileFor($user)->fill(array_intersect_key($data, array_flip(['alt_phone', 'country', 'state', 'city', 'address'])))->save();
        });

        $this->notifier->user($user, 'account_updated', ['time' => now()->format('M j, Y g:i A')]);

        return $user->fresh(['profile', 'latestIdentityVerification']);
    }

    /** Onboarding step 1 (contract §5.4). */
    public function verifyIdentity(User $user, IdType $idType, string $idNumber): IdentityVerification
    {
        $result = $this->verifier->verify($user, $idType, $idNumber);

        $verification = DB::transaction(function () use ($user, $idType, $idNumber, $result) {
            $verification = $user->identityVerifications()->create([
                'provider' => $this->verifier->name(),
                'provider_reference' => $result->providerReference,
                'id_type' => $idType,
                'id_number_last4' => substr($idNumber, -4),
                'status' => $result->status,
                'first_name' => $result->firstName,
                'last_name' => $result->lastName,
                'date_of_birth' => $result->dateOfBirth,
                'gender' => $result->gender,
                'nationality' => $result->nationality,
                'failure_reason' => $result->failureReason,
                'verified_at' => $result->status === KycStatus::Verified ? now() : null,
            ]);

            $user->forceFill(['kyc_status' => $result->status]);

            if ($result->status === KycStatus::Verified) {
                $user->forceFill(array_filter([
                    'first_name' => $result->firstName,
                    'last_name' => $result->lastName,
                ]));

                $this->profileFor($user)->fill(array_filter([
                    'date_of_birth' => $result->dateOfBirth,
                    'gender' => $result->gender,
                    'nationality' => $result->nationality,
                ]))->save();
            }

            $user->save();

            if ($result->status !== KycStatus::Failed) {
                $this->advance($user, OnboardingStep::Personal);
            }

            return $verification;
        });

        Log::info('identity.verification', ['user_id' => $user->id, 'provider' => $this->verifier->name(), 'status' => $result->status->value]);

        if ($result->status === KycStatus::Failed) {
            $this->notifier->user($user, 'identity_failed');
            throw new ApiException(
                "We couldn't verify this ID. Check the number and try again.",
                'identity_verification_failed',
                422,
                ['id_number' => ["We couldn't verify this ID. Check the number and try again."]],
            );
        }

        if ($result->status === KycStatus::Verified) {
            $this->notifier->user($user, 'identity_verified', [
                'first_name' => $user->first_name,
                'id_type' => collect(config('amcom.lookups.id_types'))->firstWhere('value', $idType->value)['label'] ?? $idType->value,
            ]);
        }

        return $verification;
    }

    /** Onboarding step 2 (contract §5.5). */
    public function updatePersonal(User $user, array $data): User
    {
        $this->assertNotChangingLocked($user, $data);

        DB::transaction(function () use ($user, $data) {
            $user->fill(array_intersect_key($data, array_flip(['first_name', 'last_name'])))->save();
            $this->profileFor($user)->fill(array_intersect_key($data, array_flip(['date_of_birth', 'gender', 'nationality', 'state_of_origin', 'lga'])))->save();
            $this->advance($user, OnboardingStep::Contact);
        });

        return $user->fresh(['profile', 'latestIdentityVerification']);
    }

    /** Onboarding step 3 (contract §5.6). */
    public function updateContact(User $user, array $data): User
    {
        DB::transaction(function () use ($user, $data) {
            $user->fill(['phone' => $data['phone']])->save();
            $this->profileFor($user)->fill(array_intersect_key($data, array_flip(['country', 'state', 'city', 'address', 'alt_phone'])))->save();
            $this->advance($user, OnboardingStep::NextOfKin);
        });

        return $user->fresh(['profile', 'latestIdentityVerification']);
    }

    /** Onboarding step 4 / Settings (contract §5.7). */
    public function updateNextOfKin(User $user, array $data): User
    {
        $firstCompletion = $user->profile_completed_at === null;

        DB::transaction(function () use ($user, $data) {
            $this->profileFor($user)->fill([
                'next_of_kin_name' => $data['full_name'],
                'next_of_kin_relationship' => $data['relationship'],
                'next_of_kin_phone' => $data['phone'],
                'next_of_kin_email' => $data['email'] ?? null,
            ])->save();

            $this->advance($user, OnboardingStep::Completed);

            if ($user->profile_completed_at === null) {
                $user->forceFill(['profile_completed_at' => now()])->save();
            }
        });

        if ($firstCompletion) {
            $this->notifier->user($user, 'profile_completed');
        }

        return $user->fresh(['profile', 'latestIdentityVerification']);
    }

    /** Moves the onboarding pointer forward only (steps can be revisited without regressing). */
    private function advance(User $user, OnboardingStep $next): void
    {
        $profile = $this->profileFor($user);
        $current = array_search($profile->onboarding_step->value, self::STEP_ORDER, true);

        if (array_search($next->value, self::STEP_ORDER, true) > $current) {
            $profile->forceFill(['onboarding_step' => $next])->save();
        }
    }

    private function assertNotChangingLocked(User $user, array $data): void
    {
        $locked = $this->lockedFields($user);

        if ($locked === []) {
            return;
        }

        $profile = $this->profileFor($user);
        $current = [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'date_of_birth' => $profile->date_of_birth?->toDateString(),
            'gender' => $profile->gender,
            'nationality' => $profile->nationality,
        ];

        foreach ($locked as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null && (string) $data[$field] !== (string) $current[$field]) {
                throw new ApiException(
                    'This field was verified with your ID and can no longer be changed.',
                    'field_locked',
                    422,
                    [$field => ['This field was verified with your ID and can no longer be changed.']],
                );
            }
        }
    }
}
