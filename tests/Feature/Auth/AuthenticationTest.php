<?php

namespace Tests\Feature\Auth;

use App\Enums\VerificationPurpose;
use App\Models\User;
use App\Notifications\UserEvent;
use App\Notifications\VerificationCodeNotification;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    public function test_register_returns_session_and_sends_verification_code(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', [
            'email' => 'New@Example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', 'new@example.com')
            ->assertJsonPath('data.user.role', 'user')
            ->assertJsonPath('data.user.email_verified_at', null)
            ->assertJsonPath('data.user.profile_completed', false)
            ->assertJsonStructure(['data' => ['token', 'expires_at', 'user' => ['id', 'permissions', 'kyc_status']]]);

        $user = User::query()->where('email', 'new@example.com')->firstOrFail();
        Notification::assertSentTo($user, VerificationCodeNotification::class, fn ($n) => $n->purpose === VerificationPurpose::EmailVerification);
        Notification::assertSentTo($user, UserEvent::class, fn ($n) => $n->event === 'welcome');
    }

    public function test_register_validates_password_strength_and_unique_email(): void
    {
        $this->customer(['email' => 'taken@example.com']);

        $response = $this->postJson('/api/v1/auth/register', [
            'email' => 'taken@example.com',
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ]);

        $this->assertApiError($response, 422, 'validation_failed');
        $response->assertJsonPath('message', 'Validation failed.')->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_success_returns_token_and_user(): void
    {
        $user = $this->customer(['email' => 'user@example.com']);

        $this->postJson('/api/v1/auth/login', ['email' => 'user@example.com', 'password' => 'Password123!', 'remember' => true])
            ->assertOk()
            ->assertJsonPath('message', 'Login successful.')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.token_type', 'Bearer');

        $token = $user->tokens()->first();
        $this->assertNotNull($token->expires_at);
        $this->assertTrue($token->expires_at->greaterThan(now()->addDays(29)), 'remember=true issues a long-lived token');
    }

    public function test_login_with_wrong_password_is_rejected_without_enumeration(): void
    {
        $this->customer(['email' => 'user@example.com']);

        $wrong = $this->postJson('/api/v1/auth/login', ['email' => 'user@example.com', 'password' => 'Nope12345!']);
        $unknown = $this->postJson('/api/v1/auth/login', ['email' => 'ghost@example.com', 'password' => 'Nope12345!']);

        $this->assertApiError($wrong, 401, 'invalid_credentials');
        $this->assertApiError($unknown, 401, 'invalid_credentials');
        $this->assertSame($wrong->json('message'), $unknown->json('message'));
    }

    public function test_suspended_account_cannot_log_in(): void
    {
        User::factory()->suspended()->create(['email' => 'sus@example.com']);

        $this->assertApiError(
            $this->postJson('/api/v1/auth/login', ['email' => 'sus@example.com', 'password' => 'Password123!']),
            403,
            'account_suspended',
        );
    }

    public function test_me_requires_authentication_and_returns_auth_user(): void
    {
        $this->assertApiError($this->getJson('/api/v1/auth/me'), 401, 'unauthenticated');

        $user = $this->actingAsUser();

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.permissions', [])
            ->assertJsonMissingPath('data.password');
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $this->customer(['email' => 'user@example.com']);
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'user@example.com', 'password' => 'Password123!'])->json('data.token');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk()->assertJsonPath('data', null);

        $this->app['auth']->forgetGuards();
        $this->assertApiError($this->withToken($token)->getJson('/api/v1/auth/me'), 401, 'unauthenticated');
    }

    public function test_expired_token_returns_token_expired(): void
    {
        $user = $this->customer();
        $token = $user->createToken('test', ['*'], now()->subMinute())->plainTextToken;

        $this->assertApiError($this->withToken($token)->getJson('/api/v1/auth/me'), 401, 'token_expired');
    }

    public function test_email_verification_with_code(): void
    {
        Notification::fake();
        $user = $this->actingAsUser($this->customer(['email_verified_at' => null]));

        $this->postJson('/api/v1/auth/email/resend')->assertOk();

        $code = null;
        Notification::assertSentTo($user, VerificationCodeNotification::class, function ($n) use (&$code) {
            $code = $n->code;

            return true;
        });

        $this->assertApiError($this->postJson('/api/v1/auth/email/verify', ['code' => $code === '000000' ? '111111' : '000000']), 422, 'validation_failed');

        $this->postJson('/api/v1/auth/email/verify', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_forgot_password_never_reveals_whether_account_exists(): void
    {
        Notification::fake();

        $known = $this->customer(['email' => 'known@example.com']);

        $a = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'known@example.com'])->assertOk();
        $b = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'unknown@example.com'])->assertOk();

        $this->assertSame($a->json('message'), $b->json('message'));
        Notification::assertSentTo($known, VerificationCodeNotification::class);
        Notification::assertSentTimes(VerificationCodeNotification::class, 1);
    }

    public function test_full_password_reset_flow_revokes_tokens(): void
    {
        Notification::fake();
        $user = $this->customer(['email' => 'reset@example.com']);
        $user->createToken('old-device');

        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'reset@example.com'])->assertOk();

        $code = null;
        Notification::assertSentTo($user, VerificationCodeNotification::class, function ($n) use (&$code) {
            $code = $n->code;

            return $n->purpose === VerificationPurpose::PasswordReset;
        });

        $resetToken = $this->postJson('/api/v1/auth/password/verify-code', ['email' => 'reset@example.com', 'code' => $code])
            ->assertOk()
            ->json('data.reset_token');

        // Same password as before is refused.
        $this->postJson('/api/v1/auth/password/reset', [
            'email' => 'reset@example.com', 'reset_token' => $resetToken,
            'password' => 'Password123!', 'password_confirmation' => 'Password123!',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->postJson('/api/v1/auth/password/reset', [
            'email' => 'reset@example.com', 'reset_token' => $resetToken,
            'password' => 'NewPass123!', 'password_confirmation' => 'NewPass123!',
        ])->assertOk();

        $this->assertSame(0, $user->tokens()->count());
        Notification::assertSentTo($user, UserEvent::class, fn ($n) => $n->event === 'password_changed');

        // Token is single-use.
        $this->assertApiError($this->postJson('/api/v1/auth/password/reset', [
            'email' => 'reset@example.com', 'reset_token' => $resetToken,
            'password' => 'Another123!', 'password_confirmation' => 'Another123!',
        ]), 422, 'reset_token_invalid');

        $this->postJson('/api/v1/auth/login', ['email' => 'reset@example.com', 'password' => 'NewPass123!'])->assertOk();
    }

    public function test_change_password_requires_correct_current_password(): void
    {
        Notification::fake();
        $this->actingAsUser();

        $this->postJson('/api/v1/auth/password/change', [
            'current_password' => 'Wrong12345!', 'password' => 'NewPass123!', 'password_confirmation' => 'NewPass123!',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->postJson('/api/v1/auth/password/change', [
            'current_password' => 'Password123!', 'password' => 'NewPass123!', 'password_confirmation' => 'NewPass123!',
        ])->assertOk();
    }

    public function test_two_factor_login_challenge_then_code(): void
    {
        Notification::fake();
        $user = $this->customer(['email' => '2fa@example.com', 'two_factor_enabled' => true]);

        $this->postJson('/api/v1/auth/login', ['email' => '2fa@example.com', 'password' => 'Password123!'])
            ->assertOk()
            ->assertJsonPath('data.two_factor_required', true)
            ->assertJsonPath('data.channel', 'email')
            ->assertJsonMissingPath('data.token');

        $code = null;
        Notification::assertSentTo($user, VerificationCodeNotification::class, function ($n) use (&$code) {
            $code = $n->code;

            return $n->purpose === VerificationPurpose::TwoFactor;
        });

        $this->postJson('/api/v1/auth/login', ['email' => '2fa@example.com', 'password' => 'Password123!', 'two_factor_code' => $code === '999999' ? '111111' : '999999'])
            ->assertStatus(422)->assertJsonValidationErrors('two_factor_code');

        $this->postJson('/api/v1/auth/login', ['email' => '2fa@example.com', 'password' => 'Password123!', 'two_factor_code' => $code])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token']]);
    }

    public function test_login_is_rate_limited_with_retry_after(): void
    {
        $this->customer(['email' => 'user@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'user@example.com', 'password' => 'Wrong12345!']);
        }

        $response = $this->postJson('/api/v1/auth/login', ['email' => 'user@example.com', 'password' => 'Wrong12345!']);

        $this->assertApiError($response, 429, 'too_many_requests');
        $response->assertHeader('Retry-After');
    }
}
