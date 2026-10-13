<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoginActivity extends Model
{
    protected $fillable = ['user_id', 'token_id', 'device', 'location', 'ip_address', 'user_agent', 'fingerprint', 'signed_out_at'];

    protected function casts(): array
    {
        return ['signed_out_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
