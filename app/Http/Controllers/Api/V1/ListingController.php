<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ListingStatus;
use App\Enums\ListingType;
use App\Enums\PropertyType;
use App\Enums\RequestKind;
use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Listings\ListingRequest;
use App\Http\Requests\Rules;
use App\Http\Resources\ListingResource;
use App\Http\Resources\MediaResource;
use App\Models\Media;
use App\Models\Property;
use App\Services\Listings\ListingService;
use App\Services\SettingsService;
use App\Support\ApiResponse;
use App\Support\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Contract §9 — owner listings. Every lookup is scoped to the owner (404 otherwise). */
class ListingController extends Controller
{
    public function __construct(private readonly ListingService $listings) {}

    public function fee(SettingsService $settings): JsonResponse
    {
        return ApiResponse::success([
            'amount' => $settings->listingFee(),
            'currency' => 'NGN',
            'description' => 'One-time registration fee covering inspection, verification and marketing.',
        ]);
    }

    public function store(ListingRequest $request): JsonResponse
    {
        $listing = $this->listings->create($request->user(), $request->validated());

        return ApiResponse::created(new ListingResource($this->load($listing)), 'Listing saved.');
    }

    public function update(ListingRequest $request, int $id): JsonResponse
    {
        $listing = $this->listings->update($this->owned($request, $id), $request->validated());

        return ApiResponse::success(new ListingResource($this->load($listing)), 'Listing updated.');
    }

    public function addPhoto(Request $request, int $id): JsonResponse
    {
        $request->validate(['photo' => Rules::image((int) config('amcom.media.max_photo_kb'), 400, 300)]);

        $media = $this->listings->addPhoto($request->user(), $this->owned($request, $id), $request->file('photo'));

        return ApiResponse::created(new MediaResource($media), 'Photo uploaded.');
    }

    public function removePhoto(Request $request, int $id, int $mediaId): JsonResponse
    {
        $listing = $this->owned($request, $id);
        $media = Media::query()->whereMorphedTo('owner', $listing)->findOrFail($mediaId);

        $this->listings->removePhoto($listing, $media);

        return ApiResponse::success(null, 'Photo removed.');
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        $request->validate(['confirm_accuracy' => ['required', 'accepted']], [
            'confirm_accuracy.*' => 'Please confirm that the information provided is accurate.',
        ]);

        $listing = $this->listings->submit($this->owned($request, $id));

        return ApiResponse::success(new ListingResource($this->load($listing)), 'Listing submitted.');
    }

    public function index(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);

        $query = $this->withActivity(Property::query()->ownedBy($request->user()))
            ->with(['photos', 'documents'])
            ->when($list->filter('listing_type', ListingType::values()), fn ($q, $v) => $q->whereIn('listing_type', $v))
            ->when($list->filter('property_type', PropertyType::values()), fn ($q, $v) => $q->whereIn('property_type', $v))
            ->when($list->filter('status', ListingStatus::values()), fn ($q, $v) => $q->whereIn('status', $v))
            ->latest('id');

        return ApiResponse::paginated($list->paginate($query, 12), ListingResource::class);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $listing = $this->withActivity(Property::query()->ownedBy($request->user()))
            ->with(['photos', 'documents'])
            ->findOrFail($id);

        return ApiResponse::success(new ListingResource($listing));
    }

    private function owned(Request $request, int $id): Property
    {
        return Property::query()->ownedBy($request->user())->findOrFail($id);
    }

    private function load(Property $listing): Property
    {
        return $this->withActivity(Property::query())->with(['photos', 'documents'])->findOrFail($listing->id);
    }

    /**
     * @param  Builder<Property>  $query
     * @return Builder<Property>
     */
    private function withActivity(Builder $query): Builder
    {
        return $query->withCount([
            'serviceRequests as interested_parties_count' => fn ($q) => $q->whereIn('kind', [RequestKind::Application, RequestKind::Inspection]),
            'serviceRequests as completed_viewings_count' => fn ($q) => $q->where('kind', RequestKind::Inspection)
                ->where('scheduled_for', '<', now())
                ->whereIn('status', [RequestStatus::Resolved, RequestStatus::Closed]),
        ]);
    }
}
