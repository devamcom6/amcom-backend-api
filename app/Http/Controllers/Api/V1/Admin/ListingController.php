<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ListingStatus;
use App\Enums\ListingType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReviewListingRequest;
use App\Http\Resources\Admin\AdminListingResource;
use App\Models\Property;
use App\Services\Listings\ListingService;
use App\Support\ApiResponse;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Contract §15.4 — listings.view / listings.review */
class ListingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);

        $query = Property::query()
            ->whereNotNull('user_id')
            ->with('owner')
            ->withCount('photos')
            ->when($list->filter('status', ListingStatus::values()), fn ($q, $v) => $q->whereIn('status', $v))
            ->when($list->filter('listing_type', ListingType::values()), fn ($q, $v) => $q->whereIn('listing_type', $v))
            ->when($list->search(), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('title', 'like', "%{$term}%")
                ->orWhere('reference', 'like', "%{$term}%")
                ->orWhere('city', 'like', "%{$term}%")
                ->orWhereHas('owner', fn ($o) => $o->where('email', 'like', "%{$term}%"))));

        $list->applySort($query, [
            'submitted_at' => 'submitted_at',
            'created_at' => 'created_at',
            'asking_price' => 'price',
        ], '-submitted_at');

        return ApiResponse::paginated($list->paginate($query, 25), AdminListingResource::class);
    }

    public function review(ReviewListingRequest $request, int $id, ListingService $listings): JsonResponse
    {
        $listing = Property::query()->whereNotNull('user_id')->findOrFail($id);
        $decision = $request->validated('decision');

        $updated = $listings->review($request->user(), $listing, $decision, $request->validated('reason'));

        return ApiResponse::success(
            new AdminListingResource(Property::query()->with('owner')->withCount('photos')->findOrFail($updated->id)),
            $decision === 'approve' ? 'Listing approved and published.' : 'Listing rejected. The owner has been notified.',
        );
    }
}
