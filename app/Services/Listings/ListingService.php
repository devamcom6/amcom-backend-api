<?php

namespace App\Services\Listings;

use App\Enums\ListingStatus;
use App\Enums\MediaCollection;
use App\Enums\PropertyType;
use App\Exceptions\ApiException;
use App\Models\Media;
use App\Models\Property;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Media\MediaService;
use App\Services\Notifier;
use App\Support\Reference;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owner listings lifecycle (contract §9):
 * draft → awaiting_payment → pending_verification → published → sold | rented | archived, or rejected.
 */
class ListingService
{
    private const EDITABLE_FIELDS = [
        'listing_type', 'property_type', 'state', 'city', 'address', 'size_sqm', 'bedrooms', 'bathrooms',
        'condition', 'furnishing', 'amenities', 'description',
    ];

    public function __construct(
        private readonly MediaService $media,
        private readonly Notifier $notifier,
        private readonly AuditLogger $audit,
    ) {}

    public function create(User $user, array $data): Property
    {
        return DB::transaction(function () use ($user, $data) {
            $property = new Property($this->attributes($data) + [
                'reference' => Reference::temporary(),
                'slug' => Reference::temporary(),
                'status' => ListingStatus::Draft,
            ]);
            $property->owner()->associate($user);
            $property->save();

            $property->forceFill([
                'reference' => sprintf('LST-%d', 2000 + $property->id),
                'slug' => Str::slug($property->title).'-'.$property->id,
            ])->save();

            return $property;
        });
    }

    public function update(Property $property, array $data): Property
    {
        if (in_array($property->status, [ListingStatus::Sold, ListingStatus::Rented, ListingStatus::Archived], true)) {
            throw ApiException::conflict('This listing can no longer be edited.', 'invalid_state');
        }

        $wasPublished = $property->status === ListingStatus::Published;

        DB::transaction(function () use ($property, $data, $wasPublished) {
            $property->fill($this->attributes($data));

            // Product decision (handoff §8 #11): edits to a live listing go back through verification.
            if ($wasPublished) {
                $property->forceFill(['status' => ListingStatus::PendingVerification, 'submitted_at' => now()]);
            } elseif ($property->status === ListingStatus::Rejected) {
                $property->forceFill(['status' => ListingStatus::Draft]);
            }

            $property->save();
        });

        if ($wasPublished) {
            $this->notifier->ops('listing_resubmitted', 'Published listing edited: '.$property->title, [
                'reference' => $property->reference,
            ], '/admin/listings?search='.$property->reference);
        }

        return $property->fresh();
    }

    public function addPhoto(User $user, Property $property, UploadedFile $file): Media
    {
        $this->assertEditable($property);

        $max = (int) config('amcom.media.max_listing_photos');

        return DB::transaction(function () use ($user, $property, $file, $max) {
            // Lock the listing so concurrent uploads can't exceed the limit.
            Property::query()->whereKey($property->id)->lockForUpdate()->first();
            $count = $property->photos()->count();

            if ($count >= $max) {
                throw ApiException::field('photo', "You can upload up to {$max} photos per listing.");
            }

            return $this->media->upload(
                $file,
                MediaCollection::ListingPhoto,
                $this->media->folder('listings', (string) $property->id),
                $property,
                $user,
                $property->title.' — photo '.($count + 1),
                position: ((int) $property->photos()->max('position')) + 1,
            );
        });
    }

    public function removePhoto(Property $property, Media $media): void
    {
        $this->assertEditable($property);
        $this->media->delete($media);
    }

    public function submit(Property $property): Property
    {
        if ($property->status === ListingStatus::AwaitingPayment || $property->status === ListingStatus::PendingVerification) {
            return $property; // idempotent
        }

        if (! in_array($property->status, [ListingStatus::Draft, ListingStatus::Rejected], true)) {
            throw ApiException::conflict('This listing has already been submitted.', 'invalid_state');
        }

        if (! $property->photos()->exists()) {
            throw ApiException::field('photo', 'Add at least one photo before submitting your listing.');
        }

        $alreadyPaid = $property->fee_paid_at !== null;

        $property->forceFill([
            'accuracy_confirmed_at' => now(),
            'rejection_reason' => null,
            'status' => $alreadyPaid ? ListingStatus::PendingVerification : ListingStatus::AwaitingPayment,
            'submitted_at' => $alreadyPaid ? now() : $property->submitted_at,
        ])->save();

        if ($alreadyPaid) {
            $this->notifySubmitted($property);
        }

        return $property->fresh();
    }

    /** Effect of a verified listing-fee payment (inside the payment DB transaction). */
    public function markFeePaid(Property $property): void
    {
        $property->forceFill([
            'fee_paid_at' => $property->fee_paid_at ?? now(),
            'status' => $property->status === ListingStatus::AwaitingPayment ? ListingStatus::PendingVerification : $property->status,
            'submitted_at' => now(),
        ])->save();
    }

    public function notifySubmitted(Property $property): void
    {
        $this->notifier->user($property->owner, 'listing_submitted', [
            'title' => $property->title,
            'reference' => $property->reference,
            'listing_id' => $property->id,
        ]);
        $this->notifier->ops('listing_submitted', 'Listing awaiting verification: '.$property->title, [
            'reference' => $property->reference,
            'owner' => $property->owner?->email,
            'location' => $property->locationLabel(),
        ], '/admin/listings?status[]=pending_verification');
    }

    /** Admin decision (contract §15.4). */
    public function review(User $admin, Property $property, string $decision, ?string $reason): Property
    {
        $updated = DB::transaction(function () use ($property, $decision, $reason) {
            $locked = Property::query()->whereKey($property->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ListingStatus::PendingVerification) {
                throw ApiException::conflict('Only listings pending verification can be reviewed.', 'invalid_state');
            }

            $locked->forceFill($decision === 'approve'
                ? ['status' => ListingStatus::Published, 'published_at' => $locked->published_at ?? now(), 'rejection_reason' => null]
                : ['status' => ListingStatus::Rejected, 'rejection_reason' => $reason])->save();

            return $locked;
        });

        $this->audit->log(
            $decision === 'approve' ? 'listing.approved' : 'listing.rejected',
            $updated,
            ['status' => ListingStatus::PendingVerification->value],
            ['status' => $updated->status->value, 'reason' => $reason],
            $decision === 'approve' ? 'approved listing' : 'rejected listing',
            $admin,
        );

        if ($updated->owner) {
            $this->notifier->user($updated->owner, $decision === 'approve' ? 'listing_approved' : 'listing_rejected', [
                'title' => $updated->title,
                'listing_id' => $updated->id,
                'reason' => $reason,
            ]);
        }

        return $updated;
    }

    public function generateTitle(array $data): string
    {
        $type = PropertyType::tryFrom((string) ($data['property_type'] ?? ''));
        $typeLabel = $type ? Str::headline($type->value) : 'Property';
        $bedrooms = (int) ($data['bedrooms'] ?? 0);

        $title = $bedrooms > 0 && ! in_array($type, [PropertyType::Land, PropertyType::Warehouse, PropertyType::Office, PropertyType::Commercial], true)
            ? "{$bedrooms} Bed {$typeLabel}"
            : $typeLabel;

        return $title.', '.($data['city'] ?? '');
    }

    private function attributes(array $data): array
    {
        $attributes = array_intersect_key($data, array_flip(self::EDITABLE_FIELDS));

        if (array_key_exists('asking_price', $data)) {
            $attributes['price'] = (int) $data['asking_price'];
        }

        if (array_key_exists('amenities', $attributes)) {
            $attributes['amenities'] = array_values(array_unique($attributes['amenities'] ?? []));
        }

        $attributes['title'] = filled($data['title'] ?? null) ? $data['title'] : $this->generateTitle($data);

        return $attributes;
    }

    private function assertEditable(Property $property): void
    {
        if (in_array($property->status, [ListingStatus::Sold, ListingStatus::Rented, ListingStatus::Archived], true)) {
            throw ApiException::conflict('This listing can no longer be edited.', 'invalid_state');
        }
    }
}
