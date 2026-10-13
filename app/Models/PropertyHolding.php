<?php

namespace App\Models;

use App\Enums\HoldingStatus;
use App\Enums\HoldingType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class PropertyHolding extends Model
{
    protected $fillable = [
        'user_id', 'property_id', 'holding_type', 'status', 'purchase_price', 'acquired_on', 'title_document',
        'ownership_status', 'rate', 'rate_period', 'period_start', 'period_end', 'renewal_date',
        'next_payment_date', 'term_label',
    ];

    protected function casts(): array
    {
        return [
            'holding_type' => HoldingType::class,
            'status' => HoldingStatus::class,
            'purchase_price' => 'integer',
            'rate' => 'integer',
            'acquired_on' => 'date',
            'period_start' => 'date',
            'period_end' => 'date',
            'renewal_date' => 'date',
            'next_payment_date' => 'date',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class)->withTrashed();
    }

    /** @return HasMany<HoldingPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(HoldingPayment::class)->orderByDesc('date')->orderByDesc('id');
    }

    /** @return HasMany<MaintenanceRequest, $this> */
    public function maintenanceRequests(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class)->latest();
    }

    /** @return MorphMany<Document, $this> */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable')->orderBy('id');
    }

    public function isPayable(): bool
    {
        return $this->holding_type !== HoldingType::OutrightPurchase
            && (int) $this->rate > 0
            && in_array($this->status, [HoldingStatus::Active, HoldingStatus::Expiring, HoldingStatus::Expired, HoldingStatus::Pending], true);
    }

    public function statusLabel(): string
    {
        $kind = match ($this->holding_type) {
            HoldingType::OutrightPurchase => 'Purchase',
            HoldingType::Lease => 'Lease',
            HoldingType::Rent => 'Rental',
        };

        return match ($this->status) {
            HoldingStatus::Active => $this->holding_type === HoldingType::OutrightPurchase ? 'Owned' : 'Active '.$kind,
            HoldingStatus::Expiring => $kind.' Expiring Soon',
            HoldingStatus::Expired => $kind.' Expired',
            HoldingStatus::Transferred => 'Transferred',
            HoldingStatus::Pending => 'Pending',
        };
    }
}
