<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\InvestmentStatus;
use App\Enums\PaymentStatus;
use App\Enums\PlotStatus;
use App\Models\Estate;
use App\Models\Investment;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Notifications\UserEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    private User $user;

    private Estate $estate;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        Notification::fake();

        $this->estate = Estate::factory()->withInventory(3, 2_000_000)->create();
        $this->user = $this->actingAsUser();
    }

    private function signedOrder(): Investment
    {
        $plot = $this->estate->plots()->first();
        $id = $this->postJson('/api/v1/buyback/orders', ['estate_id' => $this->estate->id, 'plot_ids' => [$plot->id], 'tenure_months' => 12])->json('data.id');
        $this->postJson("/api/v1/buyback/orders/{$id}/agreement/sign", ['accepted' => true])->assertOk();

        return Investment::findOrFail($id);
    }

    private function fakePaystack(string $verifyStatus = 'success', ?int $amountKobo = null): void
    {
        Http::fake([
            'api.paystack.co/transaction/initialize' => fn ($request) => Http::response([
                'status' => true,
                'message' => 'Authorization URL created',
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/abc123',
                    'access_code' => 'abc123',
                    'reference' => $request['reference'],
                ],
            ]),
            'api.paystack.co/transaction/verify/*' => function ($request) use ($verifyStatus, $amountKobo) {
                $reference = basename(parse_url($request->url(), PHP_URL_PATH));
                $tx = Transaction::query()->where('reference', $reference)->first();

                return Http::response([
                    'status' => true,
                    'data' => [
                        'id' => 4099260516,
                        'status' => $verifyStatus,
                        'reference' => $reference,
                        'amount' => $amountKobo ?? $tx?->amountInKobo(),
                        'currency' => 'NGN',
                        'channel' => 'card',
                        'fees' => 200000,
                        'gateway_response' => $verifyStatus === 'success' ? 'Approved' : 'Declined by issuing bank',
                        'paid_at' => now()->toIso8601String(),
                        'authorization' => ['authorization_code' => 'AUTH_secret', 'last4' => '4081', 'brand' => 'visa', 'bank' => 'TEST BANK'],
                    ],
                ]);
            },
        ]);
    }

    private function webhook(array $body, ?string $signature = null)
    {
        $raw = json_encode($body);

        return $this->call('POST', '/api/v1/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature ?? hash_hmac('sha512', $raw, 'sk_test_secret'),
        ], $raw);
    }

    public function test_cannot_pay_before_signing_the_agreement(): void
    {
        $plot = $this->estate->plots()->first();
        $id = $this->postJson('/api/v1/buyback/orders', ['estate_id' => $this->estate->id, 'plot_ids' => [$plot->id], 'tenure_months' => 12])->json('data.id');

        $this->assertApiError(
            $this->postJson('/api/v1/payments/initialize', ['payable_type' => 'buyback_order', 'payable_id' => $id, 'method' => 'card']),
            422,
            'agreement_unsigned',
        );
    }

    public function test_initialize_uses_server_side_amount_and_returns_checkout_url(): void
    {
        $this->fakePaystack();
        $order = $this->signedOrder();

        $response = $this->postJson('/api/v1/payments/initialize', [
            'payable_type' => 'buyback_order',
            'payable_id' => $order->id,
            'method' => 'bank_transfer',
            'callback_url' => 'https://app.amcom.test/payments/callback',
            'amount' => 1, // ignored — never trusted
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.authorization_url', 'https://checkout.paystack.com/abc123')
            ->assertJsonPath('data.amount', 2_000_000)
            ->assertJsonPath('data.currency', 'NGN');

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/transaction/initialize')
            && $r['amount'] === 200_000_000 // kobo
            && $r['channels'] === ['bank_transfer']
            && $r['callback_url'] === 'https://app.amcom.test/payments/callback'
            && $r['metadata']['payable_type'] === 'buyback_order'
            && $r->hasHeader('Authorization', 'Bearer sk_test_secret'));

        // Idempotent: a second initialise within the window reuses the same checkout.
        $again = $this->postJson('/api/v1/payments/initialize', ['payable_type' => 'buyback_order', 'payable_id' => $order->id, 'method' => 'bank_transfer']);
        $this->assertSame($response->json('data.reference'), $again->json('data.reference'));
        $this->assertSame(1, Transaction::count());
    }

    public function test_callback_url_must_be_whitelisted(): void
    {
        $order = $this->signedOrder();

        $this->postJson('/api/v1/payments/initialize', [
            'payable_type' => 'buyback_order', 'payable_id' => $order->id, 'method' => 'card',
            'callback_url' => 'https://evil.example/payments/callback',
        ])->assertStatus(422)->assertJsonValidationErrors('callback_url');
    }

    public function test_cannot_pay_for_someone_elses_order(): void
    {
        $order = $this->signedOrder();
        $this->actingAsUser();

        $this->postJson('/api/v1/payments/initialize', ['payable_type' => 'buyback_order', 'payable_id' => $order->id, 'method' => 'card'])
            ->assertStatus(422)->assertJsonValidationErrors('payable_id');
    }

    public function test_verify_success_activates_order_and_issues_documents(): void
    {
        $this->fakePaystack('success');
        $order = $this->signedOrder();
        $reference = $this->postJson('/api/v1/payments/initialize', ['payable_type' => 'buyback_order', 'payable_id' => $order->id, 'method' => 'card'])->json('data.reference');

        $response = $this->getJson("/api/v1/payments/{$reference}/verify");

        $response->assertOk()
            ->assertJsonPath('data.status', 'success')
            ->assertJsonPath('data.amount', 2_000_000)
            ->assertJsonPath('data.summary.title', 'Buyback purchase')
            ->assertJsonPath('data.summary.sections.1.0.label', 'Price')
            ->assertJsonPath('data.summary.sections.1.0.value', '₦2,000,000')
            ->assertJsonPath('data.next_url', "/dashboard/buyback/{$order->id}");

        $order->refresh();
        $this->assertSame(InvestmentStatus::Active, $order->status);
        $this->assertSame(now()->toDateString(), $order->started_at->toDateString());
        $this->assertSame(PlotStatus::Sold, $order->plots()->first()->status);

        $types = $order->documents()->pluck('type')->map->value->all();
        $this->assertContains(DocumentType::PaymentReceipt->value, $types);
        $this->assertContains(DocumentType::BuybackAgreement->value, $types);
        $this->assertContains(DocumentType::PostDatedCheque->value, $types);

        $tx = Transaction::query()->where('reference', $reference)->first();
        $this->assertArrayNotHasKey('authorization_code', $tx->authorization_data, 'reusable auth codes are never stored');
        $this->assertSame(2000, $tx->fee);

        Notification::assertSentTo($this->user, UserEvent::class, fn ($n) => $n->event === 'payment_successful');
        Notification::assertSentTo($this->user, UserEvent::class, fn ($n) => $n->event === 'documents_ready');

        // Position now appears in the portfolio.
        $this->getJson('/api/v1/me/buyback')->assertOk()->assertJsonPath('data.summary.active_positions', 1);
        $this->getJson('/api/v1/me/transactions')->assertOk()->assertJsonPath('data.0.status', 'success');
    }

    public function test_verify_failed_keeps_order_payable_and_notifies(): void
    {
        $this->fakePaystack('failed');
        $order = $this->signedOrder();
        $reference = $this->postJson('/api/v1/payments/initialize', ['payable_type' => 'buyback_order', 'payable_id' => $order->id, 'method' => 'card'])->json('data.reference');

        $this->getJson("/api/v1/payments/{$reference}/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.summary.sections', [])
            ->assertJsonPath('data.next_url', null);

        $this->assertSame(InvestmentStatus::PendingPayment, $order->fresh()->status);
        Notification::assertSentTo($this->user, UserEvent::class, fn ($n) => $n->event === 'payment_failed');
    }

    public function test_unknown_or_foreign_reference_is_payment_not_found(): void
    {
        $this->assertApiError($this->getJson('/api/v1/payments/PAY-NOPE/verify'), 404, 'payment_not_found');
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $this->assertApiError($this->webhook(['event' => 'charge.success', 'data' => ['reference' => 'X']], 'bad-signature'), 401, 'invalid_signature');
        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_webhook_success_is_applied_once_even_when_delivered_twice(): void
    {
        $this->fakePaystack('success');
        $order = $this->signedOrder();
        $reference = $this->postJson('/api/v1/payments/initialize', ['payable_type' => 'buyback_order', 'payable_id' => $order->id, 'method' => 'card'])->json('data.reference');

        $payload = ['event' => 'charge.success', 'data' => ['id' => 4099260516, 'reference' => $reference, 'status' => 'success', 'amount' => 200_000_000]];

        $this->webhook($payload)->assertOk()->assertJsonPath('success', true);
        $this->webhook($payload)->assertOk()->assertJsonPath('message', 'Already processed.');

        $this->assertSame(1, WebhookEvent::count());
        $this->assertSame('processed', WebhookEvent::first()->status);
        $this->assertSame(InvestmentStatus::Active, $order->fresh()->status);
        $this->assertSame(1, $order->documents()->where('type', DocumentType::PaymentReceipt)->count());
        Notification::assertSentToTimes($this->user, UserEvent::class, 1 + 1 + 1); // agreement_signed + payment_successful + documents_ready
    }

    public function test_amount_mismatch_is_never_credited(): void
    {
        $this->fakePaystack('success', amountKobo: 100); // ₦1 paid
        $order = $this->signedOrder();
        $reference = $this->postJson('/api/v1/payments/initialize', ['payable_type' => 'buyback_order', 'payable_id' => $order->id, 'method' => 'card'])->json('data.reference');

        $this->getJson("/api/v1/payments/{$reference}/verify")->assertOk()->assertJsonPath('data.status', 'failed');

        $this->assertSame(InvestmentStatus::PendingPayment, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Failed, Transaction::query()->where('reference', $reference)->first()->status);
    }

    public function test_frontend_redirect_alone_never_changes_state(): void
    {
        $this->fakePaystack();
        $order = $this->signedOrder();
        $reference = $this->postJson('/api/v1/payments/initialize', ['payable_type' => 'buyback_order', 'payable_id' => $order->id, 'method' => 'card'])->json('data.reference');

        // GET /payments/{ref} does not query Paystack and does not change anything.
        $this->getJson("/api/v1/payments/{$reference}")->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertSame(InvestmentStatus::PendingPayment, $order->fresh()->status);
    }

    public function test_admin_requery_requires_transactions_manage(): void
    {
        $this->fakePaystack('success');
        $order = $this->signedOrder();
        $reference = $this->postJson('/api/v1/payments/initialize', ['payable_type' => 'buyback_order', 'payable_id' => $order->id, 'method' => 'card'])->json('data.reference');

        Sanctum::actingAs($this->admin());
        $this->assertApiError($this->postJson("/api/v1/admin/transactions/{$reference}/requery"), 403, 'forbidden');

        Sanctum::actingAs($this->superAdmin());
        $this->postJson("/api/v1/admin/transactions/{$reference}/requery")
            ->assertOk()
            ->assertJsonPath('data.status', 'success')
            ->assertJsonPath('message', 'Payment updated from pending to success.');
    }
}
