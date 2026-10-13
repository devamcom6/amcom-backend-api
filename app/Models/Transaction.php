<?php

namespace App\Models;

use App\Enums\PayableType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    protected $fillable = [
        'user_id', 'reference', 'provider', 'provider_reference', 'access_code', 'authorization_url',
        'payable_type', 'payable_id', 'description', 'amount', 'fee', 'currency', 'method', 'channel', 'status',
        'gateway_response', 'authorization_data', 'metadata', 'callback_url', 'paid_at', 'failed_at',
        'verified_at', 'effects_applied_at',
    ];

    protected $hidden = ['access_code', 'authorization_data', 'metadata'];

    protected function casts(): array
    {
        return [
            'payable_type' => PayableType::class,
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'amount' => 'integer',
            'fee' => 'integer',
            'payable_id' => 'integer',
            'authorization_data' => 'array',
            'metadata' => 'array',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'verified_at' => 'datetime',
            'effects_applied_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::Success);
    }

    public function amountInKobo(): int
    {
        return $this->amount * 100;
    }
}
