<?php

namespace App\Services\Admin;

use App\Exceptions\ApiException;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/** Role permission sets (contract §15.12). Changes apply on the affected users' next request. */
class RoleService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param list<string> $permissionKeys */
    public function updatePermissions(User $actor, Role $role, array $permissionKeys): Role
    {
        if (! $role->isEditable()) {
            throw new ApiException('System roles cannot be edited.', 'system_role', 422);
        }

        $old = $role->permissions()->pluck('key')->sort()->values()->all();

        DB::transaction(function () use ($role, $permissionKeys) {
            $ids = Permission::query()->whereIn('key', $permissionKeys)->pluck('id');
            $role->permissions()->sync($ids);
            $role->touch();
        });

        $new = $role->permissions()->pluck('key')->sort()->values()->all();

        $this->audit->log('role.permissions_changed', $role, ['permissions' => $old], ['permissions' => $new], 'changed role permissions', $actor, $role->label);

        return $role->fresh();
    }
}
