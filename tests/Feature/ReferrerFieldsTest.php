<?php

namespace Tests\Feature;

use App\Http\Requests\Rules;
use App\Models\Estate;
use App\Models\Property;
use App\Models\ServiceRequest;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReferrerFieldsTest extends TestCase
{
    private function apply(Property $property, array $extra = [])
    {
        return $this->postJson("/api/v1/properties/{$property->slug}/applications", $extra + [
            'full_name' => 'Ada Okafor', 'email' => 'ada@example.com', 'phone' => '+2348035550101',
            'message' => "I'd like to know more about this apartment.", 'accept_terms' => true,
        ]);
    }

    public function test_application_stores_referrer_and_admin_api_exposes_it(): void
    {
        Notification::fake();
        $property = Property::factory()->create();

        $this->apply($property, ['referrer_name' => '  Chidi Okafor  ', 'referrer_phone' => '08021112233'])->assertCreated();

        $this->assertDatabaseHas('service_requests', [
            'kind' => 'application', 'referrer_name' => 'Chidi Okafor', 'referrer_phone' => '08021112233',
        ]);

        Sanctum::actingAs($this->admin());
        $this->getJson('/api/v1/admin/requests?kind[]=application')->assertOk()
            ->assertJsonPath('data.0.referrer_name', 'Chidi Okafor')
            ->assertJsonPath('data.0.referrer_phone', '08021112233');
    }

    public function test_referrer_fields_are_optional_and_independent(): void
    {
        Notification::fake();
        $property = Property::factory()->create();

        $this->apply($property)->assertCreated();
        $this->apply($property, ['referrer_name' => null, 'referrer_phone' => null])->assertCreated();
        $this->apply($property, ['referrer_name' => '', 'referrer_phone' => ''])->assertCreated();
        $this->apply($property, ['referrer_phone' => '+2348021112233'])->assertCreated();

        $this->assertSame(4, ServiceRequest::count());
        $this->assertSame(3, ServiceRequest::whereNull('referrer_phone')->count());
        $this->assertSame(4, ServiceRequest::whereNull('referrer_name')->count());
    }

    public function test_referrer_fields_are_validated(): void
    {
        $property = Property::factory()->create();

        $this->apply($property, ['referrer_name' => str_repeat('a', 121), 'referrer_phone' => '12345'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['referrer_name', 'referrer_phone']);

        $this->assertSame(0, ServiceRequest::count());
    }

    public function test_admin_api_sends_null_referrer_for_other_kinds(): void
    {
        $this->postJson('/api/v1/contact', [
            'full_name' => 'Ada', 'email' => 'ada@example.com', 'subject' => 'Hi', 'message' => 'Hello there, team!',
            'referrer_name' => 'Ignored',
        ]);

        Sanctum::actingAs($this->admin());
        $this->getJson('/api/v1/admin/requests?kind[]=contact')->assertOk()
            ->assertJsonPath('data.0.referrer_name', null)
            ->assertJsonPath('data.0.referrer_phone', null);
    }

    public function test_phone_inputs_are_stripped_before_validation(): void
    {
        Notification::fake();
        $property = Property::factory()->create();

        $this->apply($property, ['phone' => '+234 (803) 555-0101', 'referrer_phone' => '0802 111 2233'])->assertCreated();

        $this->assertDatabaseHas('service_requests', ['phone' => '+2348035550101', 'referrer_phone' => '08021112233']);
    }

    public function test_phone_formats_match_the_frontend_check(): void
    {
        $valid = fn (string $phone) => Validator::make(['p' => $phone], ['p' => Rules::phone()])->passes();

        foreach (['08031234567', '8031234567', '2348031234567', '+2348031234567', '+447911123456', '14155550123'] as $phone) {
            $this->assertTrue($valid($phone), $phone);
        }

        foreach (['12345', '080312345', '+1234567890123456', 'abc0803123456', '0803123456#', '+234 803 123 4567'] as $phone) {
            $this->assertFalse($valid($phone), $phone.' (spaces are stripped by middleware before this rule runs)');
        }
    }

    public function test_referrer_phone_links_the_matching_account_in_any_format(): void
    {
        Notification::fake();
        $property = Property::factory()->create();
        $chidi = $this->customer(['phone' => '08021112233']);

        foreach (['8021112233', '2348021112233', '+2348021112233', '0802 111 2233'] as $phone) {
            $this->apply($property, ['referrer_phone' => $phone])->assertCreated();
            $this->assertSame($chidi->id, ServiceRequest::latest('id')->first()->referrer_user_id, $phone);
        }
    }

    public function test_referrer_phone_links_the_matching_account_in_either_format(): void
    {
        Notification::fake();
        $property = Property::factory()->create();
        $chidi = $this->customer(['phone' => '+2348021112233']);

        $this->apply($property, ['referrer_name' => 'Chidi', 'referrer_phone' => '0802-111-2233'])->assertCreated();
        $this->assertSame($chidi->id, ServiceRequest::latest('id')->first()->referrer_user_id);

        Sanctum::actingAs($this->admin());
        $this->getJson('/api/v1/admin/requests?kind[]=application')->assertOk()
            ->assertJsonPath('data.0.referrer_user.id', $chidi->id);
    }

    public function test_referrer_is_not_linked_when_ambiguous_or_self(): void
    {
        Notification::fake();
        $property = Property::factory()->create();
        $this->customer(['phone' => '08021112233']);
        $this->customer(['phone' => '+2348021112233']);
        $self = $this->customer(['phone' => '08031112233']);

        $this->apply($property, ['referrer_phone' => '08021112233'])->assertCreated();
        $this->assertNull(ServiceRequest::latest('id')->first()->referrer_user_id, 'two accounts share the number');

        Sanctum::actingAs($self);
        $this->apply($property, ['referrer_phone' => '08031112233'])->assertCreated();
        $this->assertNull(ServiceRequest::latest('id')->first()->referrer_user_id, 'no self-referral');
    }

    public function test_direct_purchase_flows_store_referrer(): void
    {
        Storage::fake('documents');
        $chidi = $this->customer(['phone' => '08021112233']);
        $estate = Estate::factory()->withInventory(2, 2_000_000)->create();
        $this->actingAsUser();

        $this->postJson('/api/v1/buyback/orders', [
            'estate_id' => $estate->id, 'plot_ids' => [$estate->plots()->first()->id], 'tenure_months' => 12,
            'referrer_name' => 'Chidi Okafor', 'referrer_phone' => '+234 802 111 2233',
        ])->assertCreated();

        $this->postJson('/api/v1/capital/investments', [
            'amount' => 1_000_000, 'tenure_months' => 6, 'referrer_name' => 'Bola', 'referrer_phone' => null,
        ])->assertCreated();

        $this->postJson('/api/v1/capital/investments', ['amount' => 1_000_000, 'tenure_months' => 6, 'referrer_phone' => '123'])
            ->assertStatus(422)->assertJsonValidationErrors('referrer_phone');

        $this->assertDatabaseHas('investments', ['product' => 'buyback', 'referrer_name' => 'Chidi Okafor', 'referrer_phone' => '+2348021112233', 'referrer_user_id' => $chidi->id]);
        $this->assertDatabaseHas('investments', ['product' => 'capital_investment', 'referrer_name' => 'Bola', 'referrer_phone' => null, 'referrer_user_id' => null]);

        Sanctum::actingAs($this->admin());
        $list = collect($this->getJson('/api/v1/admin/investments')->assertOk()->json('data'))->keyBy('product');
        $this->assertSame('Chidi Okafor', $list['buyback']['referrer_name']);
        $this->assertSame($chidi->id, $list['buyback']['referrer_user']['id']);
        $this->assertSame('Bola', $list['capital_investment']['referrer_name']);
    }

    public function test_referrals_report_and_csv_export(): void
    {
        Notification::fake();
        $property = Property::factory()->create();
        $chidi = $this->customer(['phone' => '08021112233']);

        $this->apply($property, ['referrer_name' => 'Chidi', 'referrer_phone' => '08021112233']);
        $this->apply($property, ['referrer_name' => 'Chidi O.', 'referrer_phone' => '+2348021112233']);
        $this->apply($property, ['referrer_name' => 'Bola']);
        $this->apply($property);

        Sanctum::actingAs($this->admin());
        $from = now()->subMonth()->toDateString();
        $to = now()->toDateString();

        $report = $this->getJson("/api/v1/admin/reports/referrals?from={$from}&to={$to}&group_by=month")->assertOk()
            ->assertJsonPath('data.type', 'referrals')
            ->assertJsonPath('data.totals.0.value', 3)
            ->assertJsonCount(2, 'data.rows')
            ->json('data');

        $this->assertSame([], $report['series']);
        $this->assertContains('referrer_user', array_column($report['columns'], 'key'));
        $this->assertNotContains('referrer_user_id', array_column($report['columns'], 'key'));

        $top = collect($report['rows'])->firstWhere('referrer_user_id', $chidi->id);
        $this->assertSame($chidi->displayName(), $top['referrer_user']);
        $this->assertSame(2, $top['leads']);

        $bola = collect($report['rows'])->firstWhere('referrer', 'Bola');
        $this->assertNull($bola['referrer_user']);
        $this->assertNull($bola['referrer_user_id']);

        $csv = $this->get("/api/v1/admin/reports/referrals/export?format=csv&from={$from}&to={$to}");
        $csv->assertOk();
        $this->assertStringContainsString('Referrer,"Referrer phone","Linked account"', $csv->streamedContent());
        $this->assertStringContainsString('Bola', $csv->streamedContent());
    }
}
