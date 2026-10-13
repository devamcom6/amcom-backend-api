<?php

namespace App\Models;

use App\Enums\IdType;
use App\Enums\KycStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdentityVerification extends Model
{
    /** Fields copied from a verified ID that the customer may no longer edit. */
    public const LOCKABLE_FIELDS = ['first_name', 'last_name', 'date_of_birth', 'gender', 'nationality'];

    protected $fillable = [
        'provider', 'provider_reference', 'id_type', 'id_number_last4', 'status',
        'first_name', 'last_name', 'date_of_birth', 'gender', 'nationality',
        'failure_reason', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'id_type' => IdType::class,
            'status' => KycStatus::class,
            'date_of_birth' => 'date',
            'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return list<string> */
    public function lockedFields(): array
    {
        if ($this->status !== KycStatus::Verified) {
            return [];
        }

        return array_values(array_filter(self::LOCKABLE_FIELDS, fn ($f) => filled($this->{$f})));
    }
}
