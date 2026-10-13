<?php

namespace Database\Seeders;

use App\Enums\Role as RoleEnum;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/** Roles + the 21 permissions from config/amcom.php (idempotent; safe in production). */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('amcom.permissions') as $key => $definition) {
            Permission::query()->updateOrCreate(['key' => $key], [
                'label' => $definition['label'],
                'group' => $definition['group'],
                'description' => $definition['description'],
            ]);
        }

        Role::query()->updateOrCreate(['name' => RoleEnum::User->value], [
            'label' => 'Customer', 'description' => 'Investors, owners and tenants. Access is limited to their own records.', 'is_system' => true,
        ]);

        $admin = Role::query()->updateOrCreate(['name' => RoleEnum::Admin->value], [
            'label' => 'Admin', 'description' => 'Operations staff. Access is controlled by the permissions below.', 'is_system' => false,
        ]);

        Role::query()->updateOrCreate(['name' => RoleEnum::SuperAdmin->value], [
            'label' => 'Super Admin', 'description' => 'Full access, including administrators, roles and system settings.', 'is_system' => true,
        ]);

        // Only seed admin defaults the first time, so later edits by super admins are preserved.
        if (! $admin->permissions()->exists()) {
            $defaults = collect(config('amcom.permissions'))->filter(fn ($p) => $p['admin'])->keys();
            $admin->permissions()->sync(Permission::query()->whereIn('key', $defaults)->pluck('id'));
        }
    }
}
