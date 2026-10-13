<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Product;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Buyback\BuybackInterestRequest;
use App\Http\Requests\Buyback\CreateBuybackOrderRequest;
use App\Http\Requests\SignAgreementRequest;
use App\Http\Resources\InvestmentOrderResource;
use App\Models\Estate;
use App\Models\Investment;
use App\Services\Investments\AgreementService;
use App\Services\Investments\BuybackService;
use App\Services\Leads\LeadService;
use App\Support\ApiResponse;
use App\Support\Format;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Contract §6.4–§6.7 */
class BuybackController extends Controller
{
    public function __construct(
        private readonly BuybackService $buyback,
        private readonly AgreementService $agreements,
    ) {}

    public function interest(BuybackInterestRequest $request, LeadService $leads): JsonResponse
    {
        $lead = $leads->buybackInterest($request->validated(), $this->optionalUser());

        return ApiResponse::created([
            'id' => $lead->id,
            'reference' => $lead->reference,
            'product' => 'buyback',
            'status' => 'received',
            'created_at' => Format::iso($lead->created_at),
        ], 'Interest submitted successfully. An agent will contact you within 24–48 hours.');
    }

    public function storeOrder(CreateBuybackOrderRequest $request): JsonResponse
    {
        $estate = Estate::query()->published()->find($request->validated('estate_id'));

        if ($estate === null) {
            throw ApiException::field('estate_id', 'The selected estate is not available.');
        }

        $order = $this->buyback->createOrder(
            $request->user(),
            $estate,
            array_map('intval', $request->validated('plot_ids')),
            (int) $request->validated('tenure_months'),
        );

        return ApiResponse::created(new InvestmentOrderResource($order), 'Order created. Your plots are reserved for 30 minutes.');
    }

    public function showOrder(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success(new InvestmentOrderResource($this->findOrder($request, $id)));
    }

    public function agreement(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->agreements->present($this->findOrder($request, $id)));
    }

    public function sign(SignAgreementRequest $request, int $id): JsonResponse
    {
        $order = $this->agreements->sign($this->findOrder($request, $id), $request->validated('typed_signature_name'), $request);

        return ApiResponse::success(new InvestmentOrderResource($order->load(['estate', 'plots'])), 'Agreement signed.');
    }

    private function findOrder(Request $request, int $id): Investment
    {
        return Investment::query()
            ->ownedBy($request->user())
            ->where('product', Product::Buyback)
            ->with(['estate', 'plots', 'user'])
            ->findOrFail($id);
    }
}
