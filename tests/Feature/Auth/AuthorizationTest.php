<?php

namespace Tests\Feature\Auth;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    public function test_customer_cannot_reach_admin_endpoints(): void
    {
        $this->actingAsUser();

        foreach (['/api/v1/admin/dashboard', '/api/v1/admin/users', '/api/v1/admin/settings', '/api/v1/admin/roles'] as $url) {
            $this->assertApiError($this->getJson($url), 403, 'forbidden');
        }
    }

    public function test_admin_has_default_permissions_but_not_super_admin_ones(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/v1/admin/dashboard')->assertOk();
        $this->getJson('/api/v1/admin/users')->assertOk();
        $this->getJson('/api/v1/admin/settings')->assertOk();

        $this->assertApiError($this->getJson('/api/v1/admin/admins'), 403, 'forbidden');
        $this->assertApiError($this->getJson('/api/v1/admin/roles'), 403, 'forbidden');
        $this->assertApiError($this->putJson('/api/v1/admin/settings', ['fees' => ['listing_fee' => 1]]), 403, 'forbidden');
        $this->assertApiError($this->patchJson('/api/v1/admin/estates/1', ['is_published' => false]), 403, 'forbidden');
    }

    public function test_super_admin_has_every_permission(): void
    {
        $super = $this->superAdmin();
        Sanctum::actingAs($super);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.role', 'super_admin')
            ->assertJsonCount(Permission::count(), 'data.permissions');

        $this->getJson('/api/v1/admin/admins')->assertOk();
        $this->getJson('/api/v1/admin/roles')->assertOk();
        $this->getJson('/api/v1/admin/permissions')->assertOk()->assertJsonCount(21, 'data');
    }

    public function test_permission_revocation_applies_on_the_next_request(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/users')->assertOk();

        $role = Role::query()->where('name', 'admin')->firstOrFail();
        $role->permissions()->detach(Permission::query()->where('key', 'users.view')->value('id'));

        Sanctum::actingAs($admin->fresh());
        $this->assertApiError($this->getJson('/api/v1/admin/users'), 403, 'forbidden');
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonMissing(['users.view']);
    }

    public function test_suspended_user_is_blocked_on_every_authenticated_request(): void
    {
        $user = User::factory()->suspended()->create();
        $token = $user->createToken('device')->plainTextToken;

        $this->assertApiError($this->withToken($token)->getJson('/api/v1/auth/me'), 403, 'account_suspended');
        $this->assertApiError($this->withToken($token)->getJson('/api/v1/profile'), 403, 'account_suspended');
    }

    public function test_unverified_email_cannot_create_orders_or_pay(): void
    {
        $this->actingAsUser($this->customer(['email_verified_at' => null]));

        $this->assertApiError($this->postJson('/api/v1/buyback/orders', ['estate_id' => 1, 'plot_ids' => [1], 'tenure_months' => 12]), 403, 'email_unverified');
        $this->assertApiError($this->postJson('/api/v1/payments/initialize', ['payable_type' => 'listing_fee', 'payable_id' => 1, 'method' => 'card']), 403, 'email_unverified');
        $this->getJson('/api/v1/auth/me')->assertOk(); // still allowed
    }

    public function test_role_cannot_be_escalated_through_request_payloads(): void
    {
        $user = $this->actingAsUser();

        $this->putJson('/api/v1/profile', [
            'phone' => '+2348031234567',
            'role' => 'super_admin',
            'status' => 'active',
            'email_verified_at' => now()->toIso8601String(),
        ])->assertOk();

        $this->assertSame('user', $user->fresh()->role);
    }

    public function test_admin_users_endpoint_never_exposes_staff_accounts(): void
    {
        Sanctum::actingAs($this->admin());
        $super = $this->superAdmin();

        $this->assertApiError($this->getJson("/api/v1/admin/users/{$super->id}"), 404, 'not_found');
        $this->assertApiError($this->patchJson("/api/v1/admin/users/{$super->id}/status", ['status' => 'suspended', 'reason' => 'x']), 404, 'not_found');
    }

    public function test_customer_records_of_other_users_return_404(): void
    {
        $owner = $this->customer();
        $investment = $owner->investments()->create([
            'product' => 'capital_investment', 'reference' => 'CI-TEST-1', 'status' => 'pending_signature',
            'principal' => 1_000_000, 'tenure_months' => 6, 'roi_percent' => 18, 'projected_return' => 1_180_000,
        ]);

        $this->actingAsUser();

        $this->assertApiError($this->getJson("/api/v1/capital/investments/{$investment->id}"), 404, 'not_found');
        $this->assertApiError($this->getJson("/api/v1/capital/investments/{$investment->id}/agreement"), 404, 'not_found');
    }
}
