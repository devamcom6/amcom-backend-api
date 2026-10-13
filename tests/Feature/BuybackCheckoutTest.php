<?php

namespace Tests\Feature;

use App\Enums\InvestmentStatus;
use App\Enums\PlotStatus;
use App\Models\Estate;
use App\Models\Investment;
use App\Models\ServiceRequest;
use App\Notifications\UserEvent;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BuybackCheckoutTest extends TestCase
{
    private Estate $estate;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->estate = Estate::factory()->withInventory(5, 2_000_000)->create(['slug' => 'green-lush-estate', 'name' => 'Green Lush Estate']);
    }

    public function test_public_estate_listing_and_detail(): void
    {
        Estate::factory()->create(['is_published' => false]);

        $this->getJson('/api/v1/estates?per_page=3')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.slug', 'green-lush-estate')
            ->assertJsonPath('data.0.available_plots', 5)
            ->assertJsonPath('data.0.price_from', 2_000_000)
            ->assertJsonPath('data.0.roi_max_percent', 65);

        $this->getJson('/api/v1/estates/green-lush-estate')
            ->assertOk()
            ->assertJsonPath('data.checkout_mode', 'direct')
            ->assertJsonCount(4, 'data.tenures');

        $this->getJson('/api/v1/estates/green-lush-estate/plots')->assertOk()->assertJsonCount(5, 'data');
    }

    public function test_create_order_computes_quote_server_side_and_reserves_plots(): void
    {
        $this->actingAsUser();
        $plot = $this->estate->plots()->first();

        $response = $this->postJson('/api/v1/buyback/orders', ['estate_id' => $this->estate->id, 'plot_ids' => [$plot->id], 'tenure_months' => 12]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending_signature')
            ->assertJsonPath('data.quote.total_price', 2_000_000)
            ->assertJsonPath('data.quote.roi_percent', 18)
            ->assertJsonPath('data.quote.projected_return', 2_360_000)
            ->assertJsonPath('data.quote.profit', 360_000)
            ->assertJsonPath('data.quote.maturity_date', now()->addMonthsNoOverflow(12)->toDateString())
            ->assertJsonPath('data.plots.0.code', $plot->code);

        $this->assertMatchesRegularExpression('/^BB-\d{4}-\d{5}$/', $response->json('data.reference'));
        $this->assertSame(PlotStatus::Reserved, $plot->fresh()->status);

        $this->getJson('/api/v1/estates/green-lush-estate/plots?status[]=reserved')->assertJsonCount(1, 'data');
    }

    public function test_reserved_plot_cannot_be_ordered_by_someone_else(): void
    {
        $plot = $this->estate->plots()->first();

        $this->actingAsUser();
        $this->postJson('/api/v1/buyback/orders', ['estate_id' => $this->estate->id, 'plot_ids' => [$plot->id], 'tenure_months' => 12])->assertCreated();

        $this->actingAsUser();
        $response = $this->postJson('/api/v1/buyback/orders', ['estate_id' => $this->estate->id, 'plot_ids' => [$plot->id], 'tenure_months' => 12]);

        $this->assertApiError($response, 409, 'plot_unavailable');
        $response->assertJsonPath('message', "Plot {$plot->code} is no longer available. Please choose another.");
    }

    public function test_order_validation_rules(): void
    {
        $this->actingAsUser();
        $other = Estate::factory()->withInventory(1)->create();

        $this->postJson('/api/v1/buyback/orders', ['estate_id' => $this->estate->id, 'plot_ids' => [$this->estate->plots()->first()->id], 'tenure_months' => 7])
            ->assertStatus(422)->assertJsonValidationErrors('tenure_months');

        $this->postJson('/api/v1/buyback/orders', ['estate_id' => $this->estate->id, 'plot_ids' => [$other->plots()->first()->id], 'tenure_months' => 12])
            ->assertStatus(422)->assertJsonValidationErrors('plot_ids');

        $this->postJson('/api/v1/buyback/orders', [])->assertStatus(422)->assertJsonValidationErrors(['estate_id', 'plot_ids', 'tenure_months']);
    }

    public function test_interest_only_estate_rejects_direct_orders(): void
    {
        $estate = Estate::factory()->interestOnly()->withInventory(2)->create();
        $this->actingAsUser();

        $this->assertApiError(
            $this->postJson('/api/v1/buyback/orders', ['estate_id' => $estate->id, 'plot_ids' => [$estate->plots()->first()->id], 'tenure_months' => 12]),
            422,
            'checkout_mode_interest',
        );
    }

    public function test_visitor_can_submit_buyback_interest(): void
    {
        Notification::fake();
        $plot = $this->estate->plots()->first();

        $response = $this->postJson('/api/v1/buyback/interests', [
            'estate_id' => $this->estate->id, 'plot_ids' => [$plot->id], 'tenure_months' => 24,
            'full_name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'phone' => '+2348031234567', 'note' => 'Corner plot please',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.product', 'buyback')
            ->assertJsonPath('data.status', 'received')
            ->assertJsonPath('message', 'Interest submitted successfully. An agent will contact you within 24–48 hours.');

        $this->assertMatchesRegularExpression('/^INT-\d+$/', $response->json('data.reference'));
        $this->assertSame(PlotStatus::Available, $plot->fresh()->status, 'interests do not reserve plots');
        $this->assertDatabaseHas('service_requests', ['kind' => 'interest', 'email' => 'ada@example.com']);
        Notification::assertSentOnDemand(UserEvent::class, fn ($n, $channels, $notifiable) => $n->event === 'interest_received' && $notifiable->routes['mail'] === 'ada@example.com');
    }

    public function test_honeypot_blocks_bots(): void
    {
        $this->postJson('/api/v1/buyback/interests', [
            'estate_id' => $this->estate->id, 'plot_ids' => [$this->estate->plots()->first()->id], 'tenure_months' => 24,
            'full_name' => 'Bot', 'email' => 'bot@example.com', 'phone' => '+2348031234567', 'website' => 'http://spam',
        ])->assertStatus(422)->assertJsonValidationErrors('website');

        $this->assertSame(0, ServiceRequest::count());
    }

    public function test_agreement_preview_and_signature(): void
    {
        Notification::fake();
        $user = $this->actingAsUser();
        $orderId = $this->postJson('/api/v1/buyback/orders', ['estate_id' => $this->estate->id, 'plot_ids' => [$this->estate->plots()->first()->id], 'tenure_months' => 12])->json('data.id');

        $agreement = $this->getJson("/api/v1/buyback/orders/{$orderId}/agreement")
            ->assertOk()
            ->assertJsonPath('data.title', 'Buy-Back Agreement')
            ->assertJsonPath('data.signed_at', null)
            ->json('data');

        $this->assertStringContainsString('signature=', $agreement['document_url']);
        $this->assertNotEmpty($agreement['preview_paragraphs']);
        $this->assertGreaterThan(0, $agreement['page_count']);

        // The signed preview URL serves the PDF without a bearer token.
        $this->get($agreement['document_url'])->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->postJson("/api/v1/buyback/orders/{$orderId}/agreement/sign", ['accepted' => false])->assertStatus(422)->assertJsonValidationErrors('accepted');
        $this->postJson("/api/v1/buyback/orders/{$orderId}/agreement/sign", ['accepted' => true, 'typed_signature_name' => 'Adaeze'])->assertStatus(422)->assertJsonValidationErrors('typed_signature_name');

        $this->postJson("/api/v1/buyback/orders/{$orderId}/agreement/sign", ['accepted' => true, 'typed_signature_name' => 'Adaeze Okonkwo'])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_payment');

        $signature = Investment::find($orderId)->signatures()->firstOrFail();
        $this->assertSame('Adaeze Okonkwo', $signature->typed_signature_name);
        $this->assertSame(64, strlen($signature->document_hash));
        $this->assertNotNull($signature->ip_address);
        Notification::assertSentTo($user, UserEvent::class, fn ($n) => $n->event === 'agreement_signed' && count($n->documentIds) === 1);
    }

    public function test_expired_reservations_are_released_by_the_scheduler(): void
    {
        $this->actingAsUser();
        $plot = $this->estate->plots()->first();
        $orderId = $this->postJson('/api/v1/buyback/orders', ['estate_id' => $this->estate->id, 'plot_ids' => [$plot->id], 'tenure_months' => 12])->json('data.id');

        $this->travel(31)->minutes();
        $this->artisan('buyback:release-reservations')->assertSuccessful();

        $this->assertSame(InvestmentStatus::Cancelled, Investment::find($orderId)->status);
        $this->assertSame(PlotStatus::Available, $plot->fresh()->status);
    }

    public function test_capital_investment_limits_and_order(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/v1/capital/investments', ['amount' => 500_000, 'tenure_months' => 6])
            ->assertStatus(422)->assertJsonPath('errors.amount.0', 'The minimum investment amount is ₦1,000,000.');

        $this->postJson('/api/v1/capital/investments', ['amount' => 60_000_000, 'tenure_months' => 6])
            ->assertStatus(422)->assertJsonPath('errors.amount.0', 'For investments above ₦50,000,000, contact our investment team directly.');

        $this->postJson('/api/v1/capital/investments', ['amount' => 1_000_000, 'tenure_months' => 6])
            ->assertCreated()
            ->assertJsonPath('data.quote.amount', 1_000_000)
            ->assertJsonPath('data.quote.roi_percent', 18)
            ->assertJsonPath('data.quote.projected_return', 1_180_000)
            ->assertJsonPath('data.status', 'pending_signature');
    }
}
