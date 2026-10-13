<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PlotStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\EstateResource;
use App\Http\Resources\EstateSummaryResource;
use App\Models\Estate;
use App\Models\Plot;
use App\Support\ApiResponse;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Contract §6.1–§6.3 (public). Only published estates are visible. */
class EstateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);

        $query = Estate::query()
            ->published()
            ->withPlotStats()
            ->with(['tenures', 'gallery'])
            ->when($list->search(), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$term}%")
                ->orWhere('city', 'like', "%{$term}%")
                ->orWhere('state', 'like', "%{$term}%")))
            ->orderBy('name');

        return ApiResponse::paginated($list->paginate($query, 12), EstateSummaryResource::class);
    }

    public function show(string $slug): JsonResponse
    {
        $estate = $this->findPublished($slug);

        return ApiResponse::success(new EstateResource($estate));
    }

    public function plots(Request $request, string $slug): JsonResponse
    {
        $estate = Estate::query()->published()->where('slug', $slug)->firstOrFail();
        $statuses = ListQuery::from($request)->filter('status', PlotStatus::values());

        $plots = Plot::query()
            ->where('estate_id', $estate->id)
            ->orderBy('code')
            ->limit(1000)
            ->get()
            ->map(fn (Plot $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'size_sqm' => $p->size_sqm,
                'price' => $p->price,
                'status' => $p->effectiveStatus()->value,
            ])
            ->when($statuses !== [], fn ($c) => $c->filter(fn ($p) => in_array($p['status'], $statuses, true)))
            ->values();

        return ApiResponse::success($plots);
    }

    private function findPublished(string $slug): Estate
    {
        return Estate::query()
            ->published()
            ->withPlotStats()
            ->with(['tenures', 'gallery'])
            ->where('slug', $slug)
            ->firstOrFail();
    }
}
