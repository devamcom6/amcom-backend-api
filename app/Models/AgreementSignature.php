<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** E-signature audit trail: who accepted which document (by hash), when, and from where. */
class AgreementSignature extends Model
{
    protected $fillable = ['user_id', 'document_id', 'accepted', 'typed_signature_name', 'ip_address', 'user_agent', 'document_hash', 'signed_at'];

    protected function casts(): array
    {
        return ['accepted' => 'boolean', 'signed_at' => 'datetime'];
    }

    /** @return BelongsTo<Investment, $this> */
    public function investment(): BelongsTo
    {
        return $this->belongsTo(Investment::class);
    }
}
