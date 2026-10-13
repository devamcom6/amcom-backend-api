<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ListingType;
use App\Enums\PropertyType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Property\ApplicationRequest;
use App\Http\Requests\Property\InspectionRequest;
use App\Http\Resources\PropertyDetailResource;
use App\Http\Resources\PropertySummaryResource;
use App\Models\Property;
use App\Services\Leads\LeadService;
use App\Support\ApiResponse;
use App\Support\Format;
use App\Support\ListQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Contract §8 — public marketplace (published listings only). */
class PropertyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);

        $query = Property::query()
            ->published()
            ->with('photos')
            ->when($list->search(), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('title', 'like', "%{$term}%")
                ->orWhere('address', 'like', "%{$term}%")
                ->orWhere('city', 'like', "%{$term}%")))
            ->when($list->filter('location'), fn ($q, $locations) => $q->whereIn('city', $locations))
            ->when($list->filter('listing_type', ListingType::values()), fn ($q, $types) => $q->whereIn('listing_type', $types))
            ->when($list->filter('property_type', PropertyType::values()), fn ($q, $types) => $q->whereIn('property_type', $types))
            ->when($request->integer('min_price') > 0, fn ($q) => $q->where('price', '>=', $request->integer('min_price')))
            ->when($request->integer('max_price') > 0, fn ($q) => $q->where('price', '<=', $request->integer('max_price')))
            ->when($request->integer('bedrooms') > 0, fn ($q) => $q->where('bedrooms', '>=', $request->integer('bedrooms')));

        match ($request->query('sort')) {
            'price_asc' => $query->orderBy('price')->orderBy('id'),
            'price_desc' => $query->orderByDesc('price')->orderByDesc('id'),
            default => $query->orderByDesc('published_at')->orderByDesc('id'),
        };

        return ApiResponse::paginated($list->paginate($query, 12), PropertySummaryResource::class);
    }

    public function featured(): JsonResponse
    {
        $properties = Property::query()->published()->with('photos')
            ->orderByDesc('is_featured')->orderByDesc('published_at')
            ->limit(6)->get();

        return ApiResponse::success(PropertySummaryResource::collection($properties)->resolve(request()));
    }

    public function facets(): JsonResponse
    {
        $published = Property::query()->published();

        return ApiResponse::success([
            'locations' => (clone $published)->distinct()->orderBy('city')->pluck('city')->filter()->values(),
            'listing_types' => (clone $published)->distinct()->pluck('listing_type')->map(fn ($t) => $t->value)->sort()->values(),
            'property_types' => (clone $published)->distinct()->pluck('property_type')->map(fn ($t) => $t->value)->sort()->values(),
            'price_ranges' => config('amcom.marketplace.price_ranges'),
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        return ApiResponse::success(new PropertyDetailResource($this->findPublished($slug)));
    }

    public function similar(string $slug): JsonResponse
    {
        $property = $this->findPublished($slug);

        $similar = Property::query()->published()->with('photos')
            ->whereKeyNot($property->id)
            ->where(fn ($q) => $q->where('city', $property->city)->orWhere('property_type', $property->property_type))
            ->where('listing_type', $property->listing_type)
            ->orderByRaw('case when city = ? then 0 else 1 end', [$property->city])
            ->orderByDesc('published_at')
            ->limit(8)
            ->get();

        return ApiResponse::success(PropertySummaryResource::collection($similar)->resolve(request()));
    }

    public function application(ApplicationRequest $request, string $slug, LeadService $leads): JsonResponse
    {
        $property = $this->findPublished($slug);
        $lead = $leads->application($property, $request->validated(), $this->optionalUser());

        return ApiResponse::created([
            'id' => $lead->id,
            'reference' => $lead->reference,
            'property_title' => $property->title,
            'status' => 'received',
            'created_at' => Format::iso($lead->created_at),
        ], 'Application submitted. A confirmation email has been sent to your inbox.');
    }

    public function availability(string $slug, LeadService $leads): JsonResponse
    {
        return ApiResponse::success($leads->inspectionAvailability($this->findPublished($slug)));
    }

    public function inspection(InspectionRequest $request, string $slug, LeadService $leads): JsonResponse
    {
        $property = $this->findPublished($slug);
        $booking = $leads->bookInspection($property, $request->validated(), $this->optionalUser());
        $lead = $booking['request'];

        return ApiResponse::created([
            'id' => $lead->id,
            'reference' => $lead->reference,
            'property_title' => $property->title,
            'type' => $lead->meta['type'],
            'scheduled_for' => CarbonImmutable::parse($lead->scheduled_for)->timezone(config('amcom.inspections.timezone'))->toIso8601String(),
            'status' => 'scheduled',
            'calendar_url' => $leads->calendarUrl($lead),
        ], 'Inspection booked. A confirmation email has been sent to your inbox.');
    }

    private function findPublished(string $slug): Property
    {
        return Property::query()->published()->with('photos')->where('slug', $slug)->firstOrFail();
    }
}
