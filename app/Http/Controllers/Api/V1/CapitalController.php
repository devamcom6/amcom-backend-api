<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Product;
use App\Http\Controllers\Controller;
use App\Http\Requests\Capital\CapitalInterestRequest;
use App\Http\Requests\SignAgreementRequest;
use App\Http\Resources\InvestmentOrderResource;
use App\Models\Investment;
use App\Services\Investments\AgreementService;
use App\Services\Investments\CapitalService;
use App\Services\Leads\LeadService;
use App\Support\ApiResponse;
use App\Support\Format;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Contract §7.1–§7.4 */
class CapitalController extends Controller
{
    public function __construct(
        private readonly CapitalService $capital,
        private readonly AgreementService $agreements,
    ) {}

    public function plan(): JsonResponse
    {
        return ApiResponse::success($this->capital->plan());
    }

    public function interest(CapitalInterestRequest $request, LeadService $leads): JsonResponse
    {
        $lead = $leads->capitalInterest($request->validated(), $this->optionalUser());

        return ApiResponse::created([
            'id' => $lead->id,
            'reference' => $lead->reference,
            'product' => 'capital_investment',
            'status' => 'received',
            'created_at' => Format::iso($lead->created_at),
        ], 'Interest submitted successfully. An agent will contact you within 24–48 hours.');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'tenure_months' => ['required', 'integer', 'min:1', 'max:120'],
        ]);

        $investment = $this->capital->createInvestment($request->user(), (int) $validated['amount'], (int) $validated['tenure_months']);

        return ApiResponse::created(new InvestmentOrderResource($investment), 'Investment created.');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success(new InvestmentOrderResource($this->find($request, $id)));
    }

    public function agreement(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->agreements->present($this->find($request, $id)));
    }

    public function sign(SignAgreementRequest $request, int $id): JsonResponse
    {
        $investment = $this->agreements->sign($this->find($request, $id), $request->validated('typed_signature_name'), $request);

        return ApiResponse::success(new InvestmentOrderResource($investment), 'Agreement signed.');
    }

    private function find(Request $request, int $id): Investment
    {
        return Investment::query()
            ->ownedBy($request->user())
            ->where('product', Product::CapitalInvestment)
            ->with('user')
            ->findOrFail($id);
    }
}
