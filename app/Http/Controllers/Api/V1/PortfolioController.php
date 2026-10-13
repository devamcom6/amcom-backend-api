<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Product;
use App\Http\Controllers\Controller;
use App\Http\Resources\PositionResource;
use App\Models\Investment;
use App\Services\Investments\CapitalService;
use App\Services\Investments\PortfolioService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Contract §6.8, §7.5, §7.6 — customer portfolios. */
class PortfolioController extends Controller
{
    public function __construct(private readonly PortfolioService $portfolio) {}

    public function buyback(Request $request): JsonResponse
    {
        return $this->overview($request, Product::Buyback);
    }

    public function capital(Request $request): JsonResponse
    {
        return $this->overview($request, Product::CapitalInvestment);
    }

    public function buybackPosition(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success(new PositionResource($this->position($request, Product::Buyback, $id)));
    }

    public function capitalPosition(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success(new PositionResource($this->position($request, Product::CapitalInvestment, $id)));
    }

    public function payoutRequest(Request $request, int $id, CapitalService $capital): JsonResponse
    {
        $position = $capital->requestPayout($request->user(), $this->position($request, Product::CapitalInvestment, $id));

        return ApiResponse::success(new PositionResource($position->load('documents')), 'Payout requested. Our finance team will process it shortly.');
    }

    public function reinvest(Request $request, int $id, CapitalService $capital): JsonResponse
    {
        $validated = $request->validate(['tenure_months' => ['required', 'integer', 'min:1', 'max:120']]);

        $new = $capital->reinvest($request->user(), $this->position($request, Product::CapitalInvestment, $id), (int) $validated['tenure_months']);

        return ApiResponse::success(new PositionResource($new->load('documents')), 'Capital reinvested successfully.');
    }

    private function overview(Request $request, Product $product): JsonResponse
    {
        $positions = $this->portfolio->positions($request->user(), $product);

        return ApiResponse::success([
            'summary' => $this->portfolio->summary($positions),
            'positions' => PositionResource::collection($positions)->resolve($request),
        ]);
    }

    private function position(Request $request, Product $product, int $id): Investment
    {
        return Investment::query()
            ->ownedBy($request->user())
            ->where('product', $product)
            ->positions()
            ->with(['estate', 'plots', 'documents'])
            ->findOrFail($id);
    }
}
