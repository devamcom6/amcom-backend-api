<?php

namespace App\Models;

use App\Enums\Furnishing;
use App\Enums\ListingStatus;
use App\Enums\ListingType;
use App\Enums\MediaCollection;
use App\Enums\PropertyCondition;
use App\Enums\PropertyType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A property — either an owner listing (has user_id) or a company-listed marketplace property.
 * It is visible in the public marketplace only while `published`.
 */
class Property extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'reference', 'slug', 'title', 'listing_type', 'property_type', 'state', 'city', 'address',
        'latitude', 'longitude', 'size_sqm', 'bedrooms', 'bathrooms', 'price', 'condition', 'furnishing',
        'amenities', 'features', 'description', 'status', 'rejection_reason', 'is_featured',
        'accuracy_confirmed_at', 'fee_paid_at', 'submitted_at', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'listing_type' => ListingType::class,
            'property_type' => PropertyType::class,
            'condition' => PropertyCondition::class,
            'furnishing' => Furnishing::class,
            'status' => ListingStatus::class,
            'amenities' => 'array',
            'features' => 'array',
            'is_featured' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
            'price' => 'integer',
            'size_sqm' => 'integer',
            'bedrooms' => 'integer',
            'bathrooms' => 'integer',
            'accuracy_confirmed_at' => 'datetime',
            'fee_paid_at' => 'datetime',
            'submitted_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Listing photos and marketplace gallery, cover first.
     *
     * @return MorphMany<Media, $this>
     */
    public function photos(): MorphMany
    {
        return $this->morphMany(Media::class, 'owner')
            ->whereIn('collection', [MediaCollection::ListingPhoto, MediaCollection::PropertyGallery])
            ->orderBy('position')->orderBy('id');
    }

    /** @return MorphMany<Document, $this> */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable')->orderBy('id');
    }

    /** @return MorphMany<ServiceRequest, $this> */
    public function serviceRequests(): MorphMany
    {
        return $this->morphMany(ServiceRequest::class, 'subject');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ListingStatus::Published);
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    /** `null` for sales; rent/lease asking prices are annual (contract §9.2). */
    public function pricePeriod(): ?string
    {
        return $this->listing_type === ListingType::Sale ? null : 'year';
    }

    public function locationLabel(): string
    {
        return collect([$this->city, $this->state])->filter()->implode(', ');
    }
}
