<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InviteAdminRequest;
use App\Http\Resources\Admin\AdminAccountResource;
use App\Models\User;
use App\Services\Admin\AdminAccountService;
use App\Support\ApiResponse;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Contract §15.11 — admins.manage */
class AdminAccountController extends Controller
{
    public function __construct(private readonly AdminAccountService $admins) {}

    public function index(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);

        $query = User::query()->staff()
            ->when($list->search(), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('first_name', 'like', "%{$term}%")
                ->orWhere('last_name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")))
            ->when($list->filter('role', Role::staff()), fn ($q, $v) => $q->whereIn('role', $v));

        $list->applySort($query, ['created_at' => 'created_at', 'last_login_at' => 'last_login_at', 'email' => 'email'], '-created_at');

        return ApiResponse::paginated($list->paginate($query, 25), AdminAccountResource::class);
    }

    public function store(InviteAdminRequest $request): JsonResponse
    {
        $admin = $this->admins->invite($request->user(), $request->validated('name'), $request->validated('email'), Role::from($request->validated('role')));

        return ApiResponse::created(new AdminAccountResource($admin), 'Invitation sent.');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['role' => ['required', 'in:admin,super_admin']]);
        $target = User::query()->staff()->findOrFail($id);

        $updated = $this->admins->changeRole($request->user(), $target, Role::from($validated['role']));

        return ApiResponse::success(new AdminAccountResource($updated), 'Role updated.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $target = User::query()->staff()->findOrFail($id);
        $this->admins->revoke($request->user(), $target);

        return ApiResponse::success(null, 'Admin access revoked.');
    }
}
