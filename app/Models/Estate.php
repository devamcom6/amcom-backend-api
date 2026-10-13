<?php

namespace App\Models;

use App\Enums\CheckoutMode;
use App\Enums\MediaCollection;
use App\Enums\PlotStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Estate extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'slug', 'name', 'description', 'address', 'city', 'state', 'latitude', 'longitude',
        'size_sqm', 'checkout_mode', 'max_plots_per_order', 'is_published',
    ];

    protected function casts(): array
    {
        return [
            'checkout_mode' => CheckoutMode::class,
            'is_published' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
            'size_sqm' => 'integer',
            'max_plots_per_order' => 'integer',
        ];
    }

    /** @return HasMany<EstateTenure, $this> */
    public function tenures(): HasMany
    {
        return $this->hasMany(EstateTenure::class)->orderBy('months');
    }

    /** @return HasMany<Plot, $this> */
    public function plots(): HasMany
    {
        return $this->hasMany(Plot::class);
    }

    /** @return MorphMany<Media, $this> */
    public function gallery(): MorphMany
    {
        return $this->morphMany(Media::class, 'owner')
            ->where('collection', MediaCollection::EstateGallery)
            ->orderBy('position')->orderBy('id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /** Adds plot aggregates used by summaries (avoids N+1). */
    public function scopeWithPlotStats(Builder $query): Builder
    {
        return $query
            ->withCount([
                'plots as total_plots',
                'plots as available_plots' => fn (Builder $q) => Plot::constrainAvailableNow($q),
                'plots as reserved_plots' => fn (Builder $q) => Plot::constrainReservedNow($q),
                'plots as sold_plots' => fn (Builder $q) => $q->where('status', PlotStatus::Sold),
            ])
            ->withMin(['plots as price_from' => fn (Builder $q) => Plot::constrainAvailableNow($q)], 'price');
    }

    public function tenureFor(int $months): ?EstateTenure
    {
        return $this->tenures->firstWhere('months', $months);
    }
}
