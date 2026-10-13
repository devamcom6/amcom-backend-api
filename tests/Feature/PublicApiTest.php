<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Enums\ListingType;
use App\Models\Property;
use App\Models\ServiceRequest;
use App\Notifications\UserEvent;
use App\Services\SettingsService;
use Database\Seeders\ContentSeeder;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublicApiTest extends TestCase
{
    public function test_marketplace_filters_sorting_and_pagination(): void
    {
        Property::factory()->create(['city' => 'Lekki', 'listing_type' => ListingType::Sale, 'price' => 40_000_000, 'bedrooms' => 3]);
        Property::factory()->create(['city' => 'Ikoyi', 'listing_type' => ListingType::Rent, 'price' => 6_000_000, 'bedrooms' => 2]);
        Property::factory()->create(['city' => 'Lekki', 'listing_type' => ListingType::Sale, 'price' => 90_000_000, 'bedrooms' => 5]);
        Property::factory()->status(ListingStatus::PendingVerification)->create(['city' => 'Lekki']);

        $this->getJson('/api/v1/properties')->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('meta.per_page', 12);

        $this->getJson('/api/v1/properties?location[]=Lekki&listing_type[]=sale&sort=price_desc')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.price', 90_000_000);

        $this->getJson('/api/v1/properties?min_price=10000000&max_price=50000000')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/properties?bedrooms=3')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/properties?per_page=1')->assertJsonPath('meta.last_page', 3)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/properties?per_page=100000')->assertJsonPath('meta.per_page', 100);

        $rent = Property::query()->where('listing_type', ListingType::Rent)->first();
        $this->getJson("/api/v1/properties/{$rent->slug}")->assertJsonPath('data.price_period', 'year');

        $this->getJson('/api/v1/properties/facets')
            ->assertOk()
            ->assertJsonPath('data.locations', ['Ikoyi', 'Lekki'])
            ->assertJsonStructure(['data' => ['listing_types', 'property_types', 'price_ranges']]);
    }

    public function test_application_and_inspection_booking(): void
    {
        Notification::fake();
        $owner = $this->customer();
        $property = Property::factory()->create(['user_id' => $owner->id]);

        $this->postJson("/api/v1/properties/{$property->slug}/applications", [
            'full_name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'phone' => '+2348031234567', 'message' => 'I would like to buy this.',
        ])->assertCreated()->assertJsonPath('data.status', 'received')->assertJsonPath('data.property_title', $property->title);

        Notification::assertSentTo($owner, UserEvent::class, fn ($n) => $n->event === 'new_inquiry');

        $slots = $this->getJson("/api/v1/properties/{$property->slug}/inspection-availability")->assertOk()->json('data');
        $this->assertCount(10, $slots);
        $day = $slots[0]['date'];
        $time = $slots[0]['times'][0];

        $booking = [
            'type' => 'in_person', 'attendee' => 'self', 'full_name' => 'Ada Lovelace', 'email' => 'ada@example.com',
            'phone' => '+2348031234567', 'date' => $day, 'time' => $time, 'message' => null,
        ];

        $response = $this->postJson("/api/v1/properties/{$property->slug}/inspections", $booking)
            ->assertCreated()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.type', 'in_person');

        $this->assertStringContainsString('signature=', $response->json('data.calendar_url'));
        $this->get($response->json('data.calendar_url'))->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8');

        // Same slot again → refused; and the slot disappears from availability.
        $this->postJson("/api/v1/properties/{$property->slug}/inspections", $booking)->assertStatus(422)->assertJsonValidationErrors('time');
        $this->assertNotContains($time, $this->getJson("/api/v1/properties/{$property->slug}/inspection-availability")->json('data.0.times'));

        Notification::assertSentOnDemand(UserEvent::class, fn ($n) => $n->event === 'inspection_booked' && count($n->rawAttachments) === 1);
    }

    public function test_contact_form_creates_request_and_auto_reply(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/contact', ['full_name' => 'Ada', 'email' => 'ada@example.com', 'subject' => 'Partnership', 'message' => 'Hello Amcom team, let us talk.'])
            ->assertCreated()->assertJsonStructure(['data' => ['reference']]);

        $this->assertDatabaseHas('service_requests', ['kind' => 'contact', 'subject_line' => 'Partnership']);
        Notification::assertSentOnDemand(UserEvent::class, fn ($n) => $n->event === 'contact_received');

        $this->postJson('/api/v1/contact', ['full_name' => 'Ada', 'email' => 'not-an-email', 'subject' => 'x', 'message' => 'short'])
            ->assertStatus(422)->assertJsonValidationErrors(['email', 'message']);
    }

    public function test_content_and_lookups(): void
    {
        $this->seed(ContentSeeder::class);

        $this->getJson('/api/v1/content/home')->assertOk()->assertJsonCount(4, 'data.stats')->assertJsonCount(3, 'data.testimonials');
        $this->getJson('/api/v1/content/faqs?product=buyback')->assertOk()->assertJsonPath('data.0.product', 'buyback')->assertJsonPath('data.0.position', 1);
        $this->getJson('/api/v1/content/pages/privacy-policy')->assertOk()->assertJsonPath('data.slug', 'privacy-policy');
        $this->assertApiError($this->getJson('/api/v1/content/pages/nope'), 404, 'not_found');

        $lookups = $this->getJson('/api/v1/lookups')->assertOk()->json('data');
        $this->assertCount(37, $lookups['states']);
        $this->assertSame(774, collect($lookups['states'])->sum(fn ($s) => count($s['lgas'])));
        $this->assertNotEmpty($lookups['banks']);
        $this->assertSame('nin', $lookups['id_types'][0]['value']);
    }

    public function test_errors_never_leak_internals(): void
    {
        $this->getJson('/api/v1/does-not-exist')->assertStatus(404)->assertExactJson([
            'success' => false, 'message' => 'The requested resource was not found.', 'code' => 'not_found',
        ]);

        $this->deleteJson('/api/v1/estates')->assertStatus(405)->assertJsonPath('code', 'method_not_allowed');
    }

    public function test_maintenance_mode_blocks_customer_routes_but_not_admin(): void
    {
        app(SettingsService::class)->update(['general' => ['maintenance_mode' => true]]);

        $this->assertApiError($this->getJson('/api/v1/estates'), 503, 'maintenance');
        $this->postJson('/api/v1/auth/login', ['email' => 'x@example.com', 'password' => 'y'])->assertStatus(401);

        Sanctum::actingAs($this->admin());
        $this->getJson('/api/v1/admin/settings')->assertOk()->assertJsonPath('data.general.maintenance_mode', true);
        $this->assertSame(0, ServiceRequest::count());
    }
}
