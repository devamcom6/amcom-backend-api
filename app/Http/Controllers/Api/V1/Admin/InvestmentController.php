<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\InvestmentStatus;
use App\Enums\Product;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminInvestmentResource;
use App\Models\Investment;
use App\Services\Admin\AdminInvestmentService;
use App\Support\ApiResponse;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Contract §15.5 — investments.view / investments.manage */
class InvestmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);

        $query = Investment::query()
            ->with(['user', 'estate', 'plots'])
            ->when($list->filter('product', Product::values()), fn ($q, $v) => $q->whereIn('product', $v))
            ->when($list->filter('status', InvestmentStatus::values()), fn ($q, $v) => $q->whereIn('status', $v))
            ->when($list->search(), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('reference', 'like', "%{$term}%")
                ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$term}%")
                    ->orWhere('first_name', 'like', "%{$term}%")
                    ->orWhere('last_name', 'like', "%{$term}%"))));

        $list->applySort($query, [
            'created_at' => 'created_at',
            'principal' => 'principal',
            'maturity_date' => 'maturity_date',
        ], '-created_at');

        return ApiResponse::paginated($list->paginate($query, 25), AdminInvestmentResource::class);
    }

    public function updateStatus(Request $request, int $id, AdminInvestmentService $service): JsonResponse
    {
        $validated = $request->validate(['status' => ['required', Rule::enum(InvestmentStatus::class)]]);

        $investment = $service->changeStatus($request->user(), Investment::query()->findOrFail($id), InvestmentStatus::from($validated['status']));

        return ApiResponse::success(new AdminInvestmentResource($investment->load(['user', 'estate', 'plots'])), 'Investment updated.');
    }
}
