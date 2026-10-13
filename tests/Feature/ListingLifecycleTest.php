<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Models\Media;
use App\Models\Property;
use App\Notifications\UserEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ListingLifecycleTest extends TestCase
{
    private array $payload = [
        'listing_type' => 'sale',
        'property_type' => 'apartment',
        'state' => 'Lagos',
        'city' => 'Eti-Osa',
        'address' => '5 Admiralty Way, Lekki Phase 1',
        'size_sqm' => 200,
        'bedrooms' => 3,
        'bathrooms' => 3,
        'asking_price' => 75_000_000,
        'condition' => 'newly_built',
        'furnishing' => 'furnished',
        'amenities' => ['parking', 'security'],
        'description' => null,
    ];

    public function test_create_listing_generates_title_and_reference(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/v1/listings', $this->payload)
            ->assertCreated()
            ->assertJsonPath('data.title', '3 Bed Apartment, Eti-Osa')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.asking_price', 75_000_000)
            ->assertJsonPath('data.financial_summary', null)
            ->assertJsonPath('data.activity', null);
    }

    public function test_listing_validation(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/v1/listings', ['listing_type' => 'swap', 'amenities' => ['helipad'], 'asking_price' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['listing_type', 'property_type', 'state', 'city', 'address', 'asking_price', 'condition', 'furnishing', 'amenities.0']);
    }

    public function test_full_lifecycle_submit_pay_review_publish(): void
    {
        Notification::fake();
        Storage::fake('documents');
        $owner = $this->actingAsUser();

        $id = $this->postJson('/api/v1/listings', $this->payload)->json('data.id');

        $this->postJson("/api/v1/listings/{$id}/submit", ['confirm_accuracy' => true])
            ->assertStatus(422)->assertJsonValidationErrors('photo');

        Media::query()->forceCreate(['collection' => 'listing_photo', 'public_id' => 'amcom/listings/1', 'secure_url' => 'https://x', 'owner_type' => 'property', 'owner_id' => $id, 'width' => 1200, 'height' => 800]);

        $this->postJson("/api/v1/listings/{$id}/submit", ['confirm_accuracy' => false])->assertStatus(422)->assertJsonValidationErrors('confirm_accuracy');
        $this->postJson("/api/v1/listings/{$id}/submit", ['confirm_accuracy' => true])->assertOk()->assertJsonPath('data.status', 'awaiting_payment');

        // Pay the listing fee (amount from settings, not the client).
        Http::fake([
            'api.paystack.co/transaction/initialize' => fn ($r) => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x', 'access_code' => 'x', 'reference' => $r['reference']]]),
            'api.paystack.co/transaction/verify/*' => fn ($r) => Http::response(['status' => true, 'data' => ['id' => 1, 'status' => 'success', 'amount' => 2_500_000, 'currency' => 'NGN', 'channel' => 'card', 'paid_at' => now()->toIso8601String()]]),
        ]);

        $reference = $this->postJson('/api/v1/payments/initialize', ['payable_type' => 'listing_fee', 'payable_id' => $id, 'method' => 'card'])
            ->assertCreated()->assertJsonPath('data.amount', 25_000)->json('data.reference');

        $this->getJson("/api/v1/payments/{$reference}/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'success')
            ->assertJsonPath('data.next_url', "/dashboard/property-management/listings/{$id}");

        $this->assertSame(ListingStatus::PendingVerification, Property::find($id)->status);
        Notification::assertSentTo($owner, UserEvent::class, fn ($n) => $n->event === 'listing_submitted');

        // Not yet visible in the marketplace.
        $slug = Property::find($id)->slug;
        $this->assertApiError($this->getJson("/api/v1/properties/{$slug}"), 404, 'not_found');

        // Admin review: reject needs a reason; approve publishes.
        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/admin/listings/{$id}/review", ['decision' => 'reject'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/admin/listings/{$id}/review", ['decision' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.fee_paid', true);

        $this->assertApiError($this->postJson("/api/v1/admin/listings/{$id}/review", ['decision' => 'approve']), 409, 'invalid_state');
        Notification::assertSentTo($owner, UserEvent::class, fn ($n) => $n->event === 'listing_approved');
        $this->assertDatabaseHas('audit_logs', ['action' => 'listing.approved', 'entity_id' => $id]);

        $this->getJson("/api/v1/properties/{$slug}")->assertOk()->assertJsonPath('data.title', '3 Bed Apartment, Eti-Osa');

        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/me/listings/{$id}")
            ->assertOk()
            ->assertJsonPath('data.financial_summary.service_fee_percent', 5)
            ->assertJsonPath('data.financial_summary.service_fee', 3_750_000)
            ->assertJsonPath('data.financial_summary.net_proceeds', 71_250_000)
            ->assertJsonPath('data.activity.status_label', 'Available');
    }

    public function test_rejected_listing_carries_reason_to_owner(): void
    {
        Notification::fake();
        $owner = $this->customer();
        $listing = Property::factory()->status(ListingStatus::PendingVerification)->create(['user_id' => $owner->id]);

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/admin/listings/{$listing->id}/review", ['decision' => 'reject', 'reason' => 'Title document is illegible.'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');

        Notification::assertSentTo($owner, UserEvent::class, fn ($n) => $n->event === 'listing_rejected' && $n->data['reason'] === 'Title document is illegible.');
    }

    public function test_owner_scoping_on_my_listings(): void
    {
        $listing = Property::factory()->create(['user_id' => $this->customer()->id]);
        $this->actingAsUser();

        $this->assertApiError($this->getJson("/api/v1/me/listings/{$listing->id}"), 404, 'not_found');
        $this->assertApiError($this->putJson("/api/v1/listings/{$listing->id}", $this->payload), 404, 'not_found');
        $this->getJson('/api/v1/me/listings')->assertOk()->assertJsonPath('meta.total', 0);
    }
}
