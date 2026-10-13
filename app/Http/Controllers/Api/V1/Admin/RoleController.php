<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\RoleResource;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Admin\RoleService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Contract §15.12 — roles.manage */
class RoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $roles = Role::query()->with('permissions')->withCount('users')->orderBy('id')->get();

        return ApiResponse::success(RoleResource::collection($roles)->resolve($request));
    }

    public function permissions(): JsonResponse
    {
        return ApiResponse::success(Permission::query()->orderBy('id')->get()->map(fn (Permission $p) => [
            'key' => $p->key,
            'label' => $p->label,
            'group' => $p->group,
            'description' => $p->description,
        ])->values());
    }

    public function updatePermissions(Request $request, int $id, RoleService $roles): JsonResponse
    {
        $role = Role::query()->findOrFail($id);
        $validated = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::exists('permissions', 'key')],
        ]);

        $updated = $roles->updatePermissions($request->user(), $role, $validated['permissions']);

        return ApiResponse::success(
            new RoleResource(Role::query()->with('permissions')->withCount('users')->findOrFail($updated->id)),
            'Permissions updated. Changes apply on the next request.',
        );
    }
}
