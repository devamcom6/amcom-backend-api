<?php

namespace App\Models;

use App\Enums\OnboardingStep;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProfile extends Model
{
    protected $fillable = [
        'date_of_birth', 'gender', 'nationality', 'state_of_origin', 'lga',
        'country', 'state', 'city', 'address', 'alt_phone',
        'next_of_kin_name', 'next_of_kin_relationship', 'next_of_kin_phone', 'next_of_kin_email',
        'onboarding_step',
    ];

    protected $attributes = [
        'onboarding_step' => 'identity',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'onboarding_step' => OnboardingStep::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasNextOfKin(): bool
    {
        return filled($this->next_of_kin_name) && filled($this->next_of_kin_phone);
    }
}
