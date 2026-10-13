<?php

namespace App\Models;

use App\Enums\Product;
use App\Enums\RequestKind;
use App\Enums\RequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Leads and service requests: interest, application, inspection, contact, support. */
class ServiceRequest extends Model
{
    protected $fillable = [
        'kind', 'reference', 'user_id', 'product', 'name', 'email', 'phone', 'alt_phone',
        'subject_line', 'message', 'meta', 'status', 'scheduled_for', 'assigned_to',
    ];

    protected function casts(): array
    {
        return [
            'kind' => RequestKind::class,
            'status' => RequestStatus::class,
            'product' => Product::class,
            'meta' => 'array',
            'scheduled_for' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
