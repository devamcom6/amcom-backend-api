<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User — contract §5.1 */
class ProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $profile = $this->profile;
        $identity = $this->latestIdentityVerification;

        return [
            'user' => new AuthUserResource($this->resource),
            'identity' => [
                'status' => $identity?->status->value ?? $this->kyc_status->value,
                'id_type' => $identity?->id_type->value,
                'first_name' => $identity?->first_name,
                'last_name' => $identity?->last_name,
                'date_of_birth' => Format::ymd($identity?->date_of_birth),
                'gender' => $identity?->gender,
                'nationality' => $identity?->nationality,
                'locked_fields' => $identity?->lockedFields() ?? [],
            ],
            'personal' => [
                'first_name' => $this->first_name,
                'last_name' => $this->last_name,
                'date_of_birth' => Format::ymd($profile?->date_of_birth),
                'gender' => $profile?->gender,
                'nationality' => $profile?->nationality,
                'state_of_origin' => $profile?->state_of_origin,
                'lga' => $profile?->lga,
            ],
            'contact' => [
                'country' => $profile?->country,
                'state' => $profile?->state,
                'city' => $profile?->city,
                'address' => $profile?->address,
                'phone' => $this->phone,
                'alt_phone' => $profile?->alt_phone,
            ],
            'next_of_kin' => $profile?->hasNextOfKin() ? [
                'full_name' => $profile->next_of_kin_name,
                'relationship' => $profile->next_of_kin_relationship,
                'phone' => $profile->next_of_kin_phone,
                'email' => $profile->next_of_kin_email,
            ] : null,
            'onboarding_step' => $profile ? $profile->onboarding_step->value : 'identity',
        ];
    }
}
