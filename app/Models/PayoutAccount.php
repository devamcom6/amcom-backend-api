<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PayoutAccount extends Model
{
    use SoftDeletes;

    protected $fillable = ['bank_code', 'bank_name', 'account_number', 'account_last4', 'account_name', 'recipient_code', 'is_default'];

    protected $hidden = ['account_number', 'recipient_code'];

    protected function casts(): array
    {
        return [
            'account_number' => 'encrypted',
            'is_default' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function maskedNumber(): string
    {
        return '••••••'.$this->account_last4;
    }
}
