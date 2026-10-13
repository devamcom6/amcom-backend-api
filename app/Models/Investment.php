<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Enums\InvestmentStatus;
use App\Enums\Product;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A buyback order or capital investment. Before payment it is the "order";
 * once funded it is the "position" shown in the customer portfolio.
 */
class Investment extends Model
{
    protected $fillable = [
        'product', 'reference', 'status', 'estate_id', 'principal', 'tenure_months', 'roi_percent',
        'projected_return', 'started_at', 'maturity_date', 'agreement_signed_at', 'reservation_expires_at',
        'payout_requested_at', 'paid_out_at', 'cancelled_at', 'reinvested_from_id',
    ];

    protected function casts(): array
    {
        return [
            'product' => Product::class,
            'status' => InvestmentStatus::class,
            'principal' => 'integer',
            'tenure_months' => 'integer',
            'roi_percent' => 'float',
            'projected_return' => 'integer',
            'started_at' => 'date',
            'maturity_date' => 'date',
            'agreement_signed_at' => 'datetime',
            'reservation_expires_at' => 'datetime',
            'payout_requested_at' => 'datetime',
            'paid_out_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Estate, $this> */
    public function estate(): BelongsTo
    {
        return $this->belongsTo(Estate::class)->withTrashed();
    }

    /** @return BelongsToMany<Plot, $this> */
    public function plots(): BelongsToMany
    {
        return $this->belongsToMany(Plot::class)->withPivot('price')->orderBy('code');
    }

    /** @return MorphMany<Document, $this> */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable')->orderBy('id');
    }

    /** @return HasMany<AgreementSignature, $this> */
    public function signatures(): HasMany
    {
        return $this->hasMany(AgreementSignature::class);
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function scopePositions(Builder $query): Builder
    {
        return $query->whereIn('status', InvestmentStatus::positionStatuses());
    }

    public function isBuyback(): bool
    {
        return $this->product === Product::Buyback;
    }

    public function profit(): int
    {
        return $this->projected_return - $this->principal;
    }

    public function agreementDocumentType(): DocumentType
    {
        return $this->isBuyback() ? DocumentType::BuybackAgreement : DocumentType::InvestmentContract;
    }

    public function agreementTitle(): string
    {
        return $this->isBuyback() ? 'Buy-Back Agreement' : 'Capital Investment Agreement';
    }

    public function planName(): string
    {
        return $this->tenure_months.'-month plan';
    }

    public function monthsElapsed(): int
    {
        if ($this->started_at === null) {
            return 0;
        }

        $months = (int) CarbonImmutable::parse($this->started_at)->diffInMonths(now()->startOfDay());

        return max(0, min($this->tenure_months, $months));
    }

    public function daysToMaturity(): int
    {
        if ($this->maturity_date === null) {
            return $this->tenure_months * 30;
        }

        return max(0, (int) now()->startOfDay()->diffInDays($this->maturity_date, false));
    }

    public function canRequestPayout(): bool
    {
        return $this->product === Product::CapitalInvestment
            && $this->status === InvestmentStatus::Matured
            && $this->payout_requested_at === null;
    }

    public function canReinvest(): bool
    {
        return $this->canRequestPayout();
    }
}
