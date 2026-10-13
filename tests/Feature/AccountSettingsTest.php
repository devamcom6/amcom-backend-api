<?php

namespace Tests\Feature;

use App\Enums\VerificationPurpose;
use App\Models\PayoutAccount;
use App\Notifications\UserEvent;
use App\Notifications\VerificationCodeNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AccountSettingsTest extends TestCase
{
    public function test_payout_account_resolution_and_creation_store_masked_numbers_only(): void
    {
        Notification::fake();
        Http::fake([
            'api.paystack.co/bank?*' => Http::response(['status' => true, 'data' => [['code' => '058', 'name' => 'Guaranty Trust Bank', 'active' => true]]]),
            'api.paystack.co/bank/resolve*' => Http::response(['status' => true, 'data' => ['account_name' => 'ADAEZE OKONKWO', 'account_number' => '0123456789']]),
            'api.paystack.co/transferrecipient' => Http::response(['status' => true, 'data' => ['recipient_code' => 'RCP_test']]),
        ]);
        $user = $this->actingAsUser();

        $this->postJson('/api/v1/account/payout-accounts/resolve', ['bank_code' => '058', 'account_number' => '0123456789'])
            ->assertOk()->assertJsonPath('data.account_name', 'ADAEZE OKONKWO');

        $response = $this->postJson('/api/v1/account/payout-accounts', ['bank_code' => '058', 'account_number' => '0123456789'])
            ->assertCreated()
            ->assertJsonPath('data.account_number_masked', '••••••6789')
            ->assertJsonPath('data.bank_name', 'Guaranty Trust Bank')
            ->assertJsonPath('data.is_default', true)
            ->assertJsonMissingPath('data.account_number')
            ->assertJsonMissingPath('data.recipient_code');

        $account = PayoutAccount::query()->firstOrFail();
        $this->assertNotSame('0123456789', $account->getRawOriginal('account_number'), 'account number is encrypted at rest');
        Notification::assertSentTo($user, UserEvent::class, fn ($n) => $n->event === 'payout_account_added');

        $this->deleteJson('/api/v1/account/payout-accounts/'.$response->json('data.id'))->assertOk();
        $this->assertSoftDeleted($account);

        $this->postJson('/api/v1/account/payout-accounts', ['bank_code' => '058', 'account_number' => '123'])
            ->assertStatus(422)->assertJsonPath('errors.account_number.0', 'Account number must be 10 digits.');
    }

    public function test_unresolvable_account_number(): void
    {
        Http::fake(['api.paystack.co/bank/resolve*' => Http::response(['status' => false, 'message' => 'Could not resolve'], 422)]);
        $this->actingAsUser();

        $this->postJson('/api/v1/account/payout-accounts/resolve', ['bank_code' => '058', 'account_number' => '0000000000'])
            ->assertStatus(422)->assertJsonPath('errors.account_number.0', "We couldn't verify this account.");
    }

    public function test_notification_preferences_ignore_locked_keys(): void
    {
        $this->actingAsUser();

        $prefs = $this->getJson('/api/v1/account/notification-preferences')->assertOk()->json('data');
        $this->assertCount(10, $prefs);

        $updated = collect($this->putJson('/api/v1/account/notification-preferences', ['preferences' => ['roi_updates' => false, 'password_changes' => false, 'unknown' => true]])
            ->assertOk()->json('data'))->keyBy('key');

        $this->assertFalse($updated['roi_updates']['enabled']);
        $this->assertTrue($updated['password_changes']['enabled']);
        $this->assertTrue($updated['password_changes']['locked']);
    }

    public function test_disabled_preference_suppresses_optional_email_but_not_security_email(): void
    {
        $user = $this->customer();
        $user->notificationPreferences()->create(['key' => 'login_alerts', 'enabled' => false]);

        $this->assertSame(['database'], (new UserEvent('login_alert', ['device' => 'x']))->via($user));
        $this->assertSame(['mail', 'database'], (new UserEvent('password_changed'))->via($user));
    }

    public function test_sessions_list_and_revoke(): void
    {
        $user = $this->customer(['email' => 'sess@example.com']);
        $first = $this->postJson('/api/v1/auth/login', ['email' => 'sess@example.com', 'password' => 'Password123!'])->json('data.token');
        $second = $this->postJson('/api/v1/auth/login', ['email' => 'sess@example.com', 'password' => 'Password123!'])->json('data.token');

        $sessions = $this->withToken($second)->getJson('/api/v1/account/sessions')->assertOk()->json('data');
        $this->assertSame(['current', 'active'], array_column($sessions, 'status'));

        $this->withToken($second)->deleteJson('/api/v1/account/sessions/'.$sessions[1]['id'])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->assertApiError($this->withToken($first)->getJson('/api/v1/auth/me'), 401, 'unauthenticated');
        unset($user);
    }

    public function test_phone_change_with_code(): void
    {
        Notification::fake();
        $user = $this->actingAsUser();

        $this->postJson('/api/v1/account/phone/change-request', ['phone' => '+2348031112222'])->assertOk();

        $code = null;
        Notification::assertSentTo($user, VerificationCodeNotification::class, function ($n) use (&$code) {
            $code = $n->code;

            return $n->purpose === VerificationPurpose::PhoneChange;
        });

        $this->postJson('/api/v1/account/phone/change-confirm', ['phone' => '+2348039999999', 'code' => $code])->assertStatus(422);

        // The failed attempt above consumed nothing (wrong phone for a valid code only) — request a new code.
        $this->postJson('/api/v1/account/phone/change-request', ['phone' => '+2348031112222'])->assertOk();
        Notification::assertSentTo($user, VerificationCodeNotification::class, function ($n) use (&$code) {
            $code = $n->code;

            return true;
        });

        $this->postJson('/api/v1/account/phone/change-confirm', ['phone' => '+2348031112222', 'code' => $code])
            ->assertOk()->assertJsonPath('data.contact.phone', '+2348031112222');
    }

    public function test_delete_account_schedules_deletion_and_revokes_tokens(): void
    {
        Notification::fake();
        $user = $this->customer(['email' => 'bye@example.com']);
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'bye@example.com', 'password' => 'Password123!'])->json('data.token');

        $this->withToken($token)->deleteJson('/api/v1/account', ['confirmation' => 'delete'])->assertStatus(422);
        $this->withToken($token)->deleteJson('/api/v1/account', ['confirmation' => 'DELETE'])->assertOk();

        $this->assertSame('pending_deletion', $user->fresh()->status->value);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertApiError($this->postJson('/api/v1/auth/login', ['email' => 'bye@example.com', 'password' => 'Password123!']), 401, 'invalid_credentials');

        $this->travel(31)->days();
        $this->artisan('accounts:anonymise')->assertSuccessful();
        $this->assertSoftDeleted($user);
        $this->assertStringEndsWith('@deleted.invalid', $user->fresh()->email);
    }

    public function test_support_ticket(): void
    {
        Notification::fake();
        $this->actingAsUser();

        $this->postJson('/api/v1/support/tickets', ['category' => 'payments', 'subject' => 'Receipt missing', 'message' => 'I paid on Monday but no receipt.'])
            ->assertCreated()->assertJsonStructure(['data' => ['reference']]);

        $this->assertDatabaseHas('service_requests', ['kind' => 'support', 'subject_line' => 'Receipt missing']);
    }

    public function test_notifications_inbox(): void
    {
        $user = $this->actingAsUser();
        $user->notifyNow(new UserEvent('listing_approved', ['title' => 'Flat', 'listing_id' => 1]), ['database']);
        $user->notifyNow(new UserEvent('payout_sent', ['reference' => 'CI-1', 'amount' => '₦1']), ['database']);

        $this->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('data.unread', 2);

        $list = $this->getJson('/api/v1/notifications?unread=true')->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertSame('/dashboard/property-management/listings/1', collect($list->json('data'))->firstWhere('category', 'listing')['action_url']);

        $this->postJson('/api/v1/notifications/'.$list->json('data.0.id').'/read')->assertOk()->assertJsonPath('data.read_at', fn ($v) => $v !== null);
        $this->postJson('/api/v1/notifications/read-all')->assertOk();
        $this->getJson('/api/v1/notifications/unread-count')->assertJsonPath('data.unread', 0);
    }
}
