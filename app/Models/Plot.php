<?php

namespace App\Models;

use App\Enums\PlotStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Plot extends Model
{
    protected $fillable = ['code', 'size_sqm', 'price', 'status', 'reserved_by_investment_id', 'reserved_until'];

    protected function casts(): array
    {
        return [
            'status' => PlotStatus::class,
            'size_sqm' => 'integer',
            'price' => 'integer',
            'reserved_until' => 'datetime',
        ];
    }

    /** @return BelongsTo<Estate, $this> */
    public function estate(): BelongsTo
    {
        return $this->belongsTo(Estate::class);
    }

    /** Status as customers should see it: lapsed reservations count as available. */
    public function effectiveStatus(): PlotStatus
    {
        if ($this->status === PlotStatus::Reserved && $this->reserved_until !== null && $this->reserved_until->isPast()) {
            return PlotStatus::Available;
        }

        return $this->status;
    }

    public function scopeAvailableNow(Builder $query): Builder
    {
        return self::constrainAvailableNow($query);
    }

    public function scopeReservedNow(Builder $query): Builder
    {
        return self::constrainReservedNow($query);
    }

    /** Available, or reserved by a hold that has lapsed. Usable on any plots query or relation. */
    public static function constrainAvailableNow(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('status', PlotStatus::Available)
            ->orWhere(fn (Builder $r) => $r->where('status', PlotStatus::Reserved)->where('reserved_until', '<', now())));
    }

    public static function constrainReservedNow(Builder $query): Builder
    {
        return $query->where('status', PlotStatus::Reserved)
            ->where(fn (Builder $q) => $q->whereNull('reserved_until')->orWhere('reserved_until', '>=', now()));
    }
}
