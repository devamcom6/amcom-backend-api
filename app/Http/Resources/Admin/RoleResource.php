<?php

namespace App\Http\Resources\Admin;

use App\Enums\Role as RoleEnum;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Role — RoleDefinition (contract §15.12). Expects users_count. */
class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $permissions = $this->name === RoleEnum::SuperAdmin->value
            ? Permission::query()->orderBy('id')->pluck('key')->all()
            : $this->permissions->sortBy('id')->pluck('key')->values()->all();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'label' => $this->label,
            'description' => $this->description,
            'is_system' => ! $this->isEditable(),
            'users_count' => (int) ($this->resource->getAttributes()['users_count'] ?? 0),
            'permissions' => $permissions,
        ];
    }
}
