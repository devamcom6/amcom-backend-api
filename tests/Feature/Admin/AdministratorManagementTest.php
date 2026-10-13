<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\UserEvent;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdministratorManagementTest extends TestCase
{
    public function test_super_admin_invites_an_admin_and_it_is_audited(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->superAdmin());

        $this->postJson('/api/v1/admin/admins', ['name' => 'Amina Bello', 'email' => 'amina@amcom.com', 'role' => 'admin'])
            ->assertCreated()
            ->assertJsonPath('data.email', 'amina@amcom.com')
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.status', 'invited');

        $invited = User::query()->where('email', 'amina@amcom.com')->firstOrFail();
        Notification::assertSentTo($invited, UserEvent::class, fn ($n) => $n->event === 'admin_invited' && str_contains($n->data['invite_path'], 'reset_token='));
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.invited', 'entity_id' => $invited->id]);

        // Invited accounts cannot log in until they set a password.
        $this->assertApiError($this->postJson('/api/v1/auth/login', ['email' => 'amina@amcom.com', 'password' => 'whatever']), 401, 'invalid_credentials');
    }

    public function test_inviting_an_existing_admin_email_fails(): void
    {
        Sanctum::actingAs($this->superAdmin());
        $this->admin(['email' => 'dupe@amcom.com']);

        $this->postJson('/api/v1/admin/admins', ['name' => 'Dupe', 'email' => 'dupe@amcom.com', 'role' => 'admin'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_admins_cannot_modify_themselves(): void
    {
        $super = $this->superAdmin();
        $this->superAdmin(); // another super admin exists
        Sanctum::actingAs($super);

        $this->assertApiError($this->patchJson("/api/v1/admin/admins/{$super->id}", ['role' => 'admin']), 422, 'self_modification');
        $this->assertApiError($this->deleteJson("/api/v1/admin/admins/{$super->id}"), 422, 'self_modification');
    }

    public function test_admin_with_admins_manage_cannot_touch_super_admins_or_grant_super_admin(): void
    {
        Role::query()->where('name', 'admin')->firstOrFail()
            ->permissions()->attach(Permission::query()->where('key', 'admins.manage')->value('id'));

        $admin = $this->admin();
        $super = $this->superAdmin();
        $otherAdmin = $this->admin();
        Sanctum::actingAs($admin);

        $this->assertApiError($this->patchJson("/api/v1/admin/admins/{$super->id}", ['role' => 'admin']), 403, 'forbidden');
        $this->assertApiError($this->deleteJson("/api/v1/admin/admins/{$super->id}"), 403, 'forbidden');
        $this->assertApiError($this->patchJson("/api/v1/admin/admins/{$otherAdmin->id}", ['role' => 'super_admin']), 403, 'forbidden');
        $this->assertApiError($this->postJson('/api/v1/admin/admins', ['name' => 'X Y', 'email' => 'x@amcom.com', 'role' => 'super_admin']), 403, 'forbidden');

        $this->assertSame('super_admin', $super->fresh()->role);
        $this->assertSame('admin', $otherAdmin->fresh()->role);
    }

    public function test_cannot_demote_the_final_active_super_admin(): void
    {
        $actor = $this->superAdmin();
        $target = $this->superAdmin();
        Sanctum::actingAs($actor);

        // Race simulation: the actor is suspended in the DB mid-request, so the target is the last
        // ACTIVE super admin. The guard re-counts under a lock and refuses.
        $actor->forceFill(['status' => 'suspended'])->saveQuietly();
        Sanctum::actingAs($actor->fresh()->forceFill(['status' => 'active']));

        $this->assertApiError($this->patchJson("/api/v1/admin/admins/{$target->id}", ['role' => 'admin']), 422, 'last_super_admin');
        $this->assertSame('super_admin', $target->fresh()->role);
    }

    public function test_revoking_an_admin_demotes_and_signs_them_out(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->superAdmin());
        $admin = $this->admin();
        $admin->createToken('device');

        $this->deleteJson("/api/v1/admin/admins/{$admin->id}")->assertOk();

        $this->assertSame('user', $admin->fresh()->role);
        $this->assertSame(0, $admin->tokens()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.revoked']);
    }

    public function test_system_roles_cannot_be_edited_and_admin_role_changes_are_audited(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $super = Role::query()->where('name', 'super_admin')->firstOrFail();
        $customer = Role::query()->where('name', 'user')->firstOrFail();
        $admin = Role::query()->where('name', 'admin')->firstOrFail();

        $this->assertApiError($this->putJson("/api/v1/admin/roles/{$super->id}/permissions", ['permissions' => []]), 422, 'system_role');
        $this->assertApiError($this->putJson("/api/v1/admin/roles/{$customer->id}/permissions", ['permissions' => ['users.view']]), 422, 'system_role');

        $this->putJson("/api/v1/admin/roles/{$admin->id}/permissions", ['permissions' => ['dashboard.view', 'users.view']])
            ->assertOk()
            ->assertJsonPath('data.permissions', ['dashboard.view', 'users.view'])
            ->assertJsonPath('data.is_system', false);

        $this->putJson("/api/v1/admin/roles/{$admin->id}/permissions", ['permissions' => ['not.a.permission']])
            ->assertStatus(422)->assertJsonValidationErrors('permissions.0');

        $log = AuditLog::query()->where('action', 'role.permissions_changed')->firstOrFail();
        $this->assertSame(['dashboard.view', 'users.view'], $log->new_values['permissions']);
    }
}
