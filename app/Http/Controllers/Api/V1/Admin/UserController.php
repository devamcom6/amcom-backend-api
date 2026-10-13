<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\InvestmentStatus;
use App\Enums\KycStatus;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BulkUserActionRequest;
use App\Http\Requests\Admin\UpdateUserStatusRequest;
use App\Http\Resources\Admin\AdminUserDetailResource;
use App\Http\Resources\Admin\AdminUserResource;
use App\Models\User;
use App\Services\Admin\AdminUserService;
use App\Support\ApiResponse;
use App\Support\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Contract §15.2 — customer accounts only (staff are managed under /admin/admins). */
class UserController extends Controller
{
    public function __construct(private readonly AdminUserService $users) {}

    public function index(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);

        $query = $this->withAggregates(User::query()->customers())
            ->when($list->search(), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('first_name', 'like', "%{$term}%")
                ->orWhere('last_name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")))
            ->when($list->filter('status', UserStatus::values()), fn ($q, $v) => $q->whereIn('status', $v))
            ->when($list->filter('kyc_status', KycStatus::values()), fn ($q, $v) => $q->whereIn('kyc_status', $v));

        $list->applySort($query, [
            'name' => fn (Builder $q, string $dir) => $q->orderBy('first_name', $dir)->orderBy('last_name', $dir),
            'total_invested' => 'total_invested',
            'created_at' => 'created_at',
            'last_login_at' => 'last_login_at',
        ], '-created_at');

        return ApiResponse::paginated($list->paginate($query, 25), AdminUserResource::class);
    }

    public function show(int $id): JsonResponse
    {
        $user = $this->withAggregates(User::query()->customers())
            ->with(['profile', 'investments', 'transactions'])
            ->findOrFail($id);

        return ApiResponse::success(new AdminUserDetailResource($user));
    }

    public function updateStatus(UpdateUserStatusRequest $request, int $id): JsonResponse
    {
        $user = User::query()->customers()->findOrFail($id);
        $updated = $this->users->changeStatus($request->user(), $user, UserStatus::from($request->validated('status')), $request->validated('reason'));

        return ApiResponse::success(
            new AdminUserResource($this->withAggregates(User::query())->findOrFail($updated->id)),
            $updated->status === UserStatus::Suspended ? 'User suspended.' : 'User reactivated.',
        );
    }

    public function bulk(BulkUserActionRequest $request): JsonResponse
    {
        $result = $this->users->bulk($request->user(), $request->validated('action'), $request->validated('ids'));

        return ApiResponse::success($result, "{$result['processed']} processed, {$result['failed']} failed.");
    }

    private function withAggregates(Builder $query): Builder
    {
        $funded = [InvestmentStatus::Active, InvestmentStatus::Matured, InvestmentStatus::PaidOut];

        return $query
            ->withCount(['investments as investments_count' => fn ($q) => $q->whereIn('status', $funded)])
            ->withSum(['investments as total_invested' => fn ($q) => $q->whereIn('status', $funded)], 'principal');
    }
}
