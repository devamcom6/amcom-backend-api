<?php

namespace Tests\Feature\Admin;

use App\Enums\InvestmentStatus;
use App\Models\Estate;
use App\Models\User;
use App\Notifications\UserEvent;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminOperationsTest extends TestCase
{
    public function test_dashboard_aggregates(): void
    {
        $this->customer();
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/v1/admin/dashboard?from=2026-09-13&to=2026-10-12')
            ->assertOk()
            ->assertJsonPath('data.range', ['from' => '2026-09-13', 'to' => '2026-10-12'])
            ->assertJsonStructure(['data' => [
                'summary' => ['total_users', 'active_users', 'total_transactions', 'total_revenue', 'assets_under_management', 'pending_listings', 'open_requests'],
                'trends' => ['users', 'revenue', 'transactions', 'assets_under_management'],
                'revenue_series', 'signups_series', 'status_distribution', 'transaction_summary', 'recent_activity',
            ]]);

        $this->getJson('/api/v1/admin/dashboard?from=2026-10-12&to=2026-09-01')->assertStatus(422)->assertJsonValidationErrors('to');
    }

    public function test_users_search_filter_sort_and_suspend(): void
    {
        Notification::fake();
        $target = $this->customer(['first_name' => 'Tunde', 'email' => 'tunde@example.com']);
        $target->createToken('device');
        $this->customer(['first_name' => 'Ada']);
        Sanctum::actingAs($admin = $this->admin());

        $this->getJson('/api/v1/admin/users?search=tunde')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.email', 'tunde@example.com');
        $this->getJson('/api/v1/admin/users?sort=-total_invested&sort_injection=1')->assertOk();
        $this->getJson('/api/v1/admin/users?sort=password')->assertOk(); // unknown sort falls back safely

        $this->patchJson("/api/v1/admin/users/{$target->id}/status", ['status' => 'suspended'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->patchJson("/api/v1/admin/users/{$target->id}/status", ['status' => 'suspended', 'reason' => 'Fraud review'])
            ->assertOk()->assertJsonPath('data.status', 'suspended');

        $this->assertSame(0, $target->tokens()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.status_changed', 'entity_id' => $target->id, 'user_id' => $admin->id]);
        Notification::assertSentTo($target, UserEvent::class, fn ($n) => $n->event === 'account_suspended');

        $this->getJson('/api/v1/admin/users?status[]=suspended')->assertJsonPath('meta.total', 1);
    }

    public function test_bulk_actions_report_processed_and_failed(): void
    {
        Notification::fake();
        $a = $this->customer();
        $b = $this->customer();
        $staff = $this->admin();
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/admin/users/bulk', ['action' => 'suspend', 'ids' => [$a->id, $b->id, $staff->id]])
            ->assertOk()
            ->assertJsonPath('data', ['processed' => 2, 'failed' => 1]);

        $this->assertSame('active', $staff->fresh()->status->value);
    }

    public function test_investment_status_transitions_are_validated(): void
    {
        Notification::fake();
        $owner = $this->customer();
        $estate = Estate::factory()->withInventory(1)->create();
        $plot = $estate->plots()->first();

        $order = $owner->investments()->create([
            'product' => 'buyback', 'reference' => 'BB-T-1', 'status' => 'pending_payment', 'estate_id' => $estate->id,
            'principal' => 2_000_000, 'tenure_months' => 12, 'roi_percent' => 18, 'projected_return' => 2_360_000,
        ]);
        $order->plots()->attach($plot->id, ['price' => $plot->price]);
        $plot->forceFill(['status' => 'reserved', 'reserved_by_investment_id' => $order->id, 'reserved_until' => now()->addMinutes(20)])->save();

        $active = $owner->investments()->create([
            'product' => 'capital_investment', 'reference' => 'CI-T-1', 'status' => 'active',
            'principal' => 1_000_000, 'tenure_months' => 6, 'roi_percent' => 18, 'projected_return' => 1_180_000,
        ]);

        Sanctum::actingAs($this->admin());

        $this->assertApiError($this->patchJson("/api/v1/admin/investments/{$active->id}/status", ['status' => 'paid_out']), 409, 'invalid_state');
        $this->assertApiError($this->patchJson("/api/v1/admin/investments/{$active->id}/status", ['status' => 'cancelled']), 409, 'invalid_state');

        $this->patchJson("/api/v1/admin/investments/{$order->id}/status", ['status' => 'cancelled'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('available', $plot->fresh()->status->value, 'cancelling releases reserved plots');

        $active->forceFill(['status' => InvestmentStatus::Matured])->save();
        $this->patchJson("/api/v1/admin/investments/{$active->id}/status", ['status' => 'paid_out'])->assertOk()->assertJsonPath('data.status', 'paid_out');

        Notification::assertSentTo($owner, UserEvent::class, fn ($n) => $n->event === 'payout_sent');
    }

    public function test_settings_update_requires_settings_manage_and_validates_dot_keys(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->putJson('/api/v1/admin/settings', ['fees' => ['listing_fee' => -5], 'capital_investment' => ['min_amount' => 'abc']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fees.listing_fee', 'capital_investment.min_amount']);

        $this->putJson('/api/v1/admin/settings', ['fees' => ['listing_fee' => 30000], 'capital_investment' => ['tenures' => [['months' => 9, 'roi_percent' => 22]]]])
            ->assertOk()
            ->assertJsonPath('data.fees.listing_fee', 30000)
            ->assertJsonPath('data.fees.sale_service_fee_percent', 5);

        $this->getJson('/api/v1/capital/plan')->assertJsonPath('data.tenures', [['months' => 9, 'roi_percent' => 22]]);
        $this->getJson('/api/v1/listings/fee')->assertJsonPath('data.amount', 30000);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.updated']);

        $body = $this->getJson('/api/v1/admin/settings')->getContent();
        $this->assertStringNotContainsString('sk_test_secret', $body);
        $this->assertStringNotContainsString('test-secret', $body);
    }

    public function test_requests_queue_assignment(): void
    {
        $this->postJson('/api/v1/contact', ['full_name' => 'Ada', 'email' => 'ada@example.com', 'subject' => 'Hi', 'message' => 'Hello there, team!']);
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $id = $this->getJson('/api/v1/admin/requests?kind[]=contact')->assertOk()->assertJsonPath('meta.total', 1)->json('data.0.id');

        $this->patchJson("/api/v1/admin/requests/{$id}", ['status' => 'in_progress', 'assigned_to_me' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.assigned_to.id', $admin->id);
    }

    public function test_reports_and_csv_export(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/v1/admin/reports/users?from=2026-01-01&to=2026-10-12&group_by=month')
            ->assertOk()
            ->assertJsonPath('data.type', 'users')
            ->assertJsonPath('data.group_by', 'month')
            ->assertJsonStructure(['data' => ['totals', 'series', 'columns', 'rows']]);

        $this->assertApiError($this->getJson('/api/v1/admin/reports/secrets'), 404, 'not_found');

        $csv = $this->get('/api/v1/admin/reports/revenue/export?format=csv');
        $csv->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('attachment; filename=amcom-revenue-report', $csv->headers->get('Content-Disposition'));
    }

    public function test_estate_publish_toggle_requires_estates_manage(): void
    {
        $estate = Estate::factory()->create();

        Sanctum::actingAs($this->admin());
        $this->getJson('/api/v1/admin/estates')->assertOk()->assertJsonPath('data.0.id', $estate->id);
        $this->assertApiError($this->patchJson("/api/v1/admin/estates/{$estate->id}", ['is_published' => false]), 403, 'forbidden');

        Sanctum::actingAs($this->superAdmin());
        $this->patchJson("/api/v1/admin/estates/{$estate->id}", ['is_published' => false])->assertOk()->assertJsonPath('data.is_published', false);
        $this->assertApiError($this->getJson("/api/v1/estates/{$estate->slug}"), 404, 'not_found');
    }

    public function test_faq_crud(): void
    {
        Sanctum::actingAs($this->admin());

        $id = $this->postJson('/api/v1/admin/content/faqs', ['product' => 'buyback', 'question' => 'What is buyback?', 'answer' => 'A plot purchase with guaranteed buy back.'])
            ->assertCreated()->json('data.id');

        $this->putJson("/api/v1/admin/content/faqs/{$id}", ['product' => 'buyback', 'question' => 'Q?', 'answer' => 'Answer text'])
            ->assertStatus(422)->assertJsonValidationErrors('question');

        $this->putJson("/api/v1/admin/content/faqs/{$id}", ['product' => 'buyback', 'question' => 'What is a buyback?', 'answer' => 'Answer text', 'is_published' => false])
            ->assertOk()->assertJsonPath('data.is_published', false);

        $this->getJson('/api/v1/content/faqs?product=buyback')->assertJsonCount(0, 'data');
        $this->deleteJson("/api/v1/admin/content/faqs/{$id}")->assertOk();
        unset($id);
        $this->assertSame(1, User::query()->staff()->count());
    }
}
