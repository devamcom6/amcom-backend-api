<?php

namespace Tests\Feature;

use App\Notifications\UserEvent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    public function test_profile_shape_for_a_new_user(): void
    {
        $this->actingAsUser($this->customer(['first_name' => null, 'last_name' => null]));

        $this->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.onboarding_step', 'identity')
            ->assertJsonPath('data.identity.status', 'unverified')
            ->assertJsonPath('data.identity.locked_fields', [])
            ->assertJsonPath('data.next_of_kin', null)
            ->assertJsonStructure(['data' => ['user', 'identity', 'personal', 'contact', 'next_of_kin', 'onboarding_step']]);
    }

    public function test_full_onboarding_flow(): void
    {
        Notification::fake();
        $user = $this->actingAsUser($this->customer(['first_name' => null, 'last_name' => null]));

        $this->postJson('/api/v1/profile/identity/verify', ['id_type' => 'nin', 'id_number' => '12345678901'])
            ->assertOk()
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.locked_fields', ['first_name', 'last_name', 'date_of_birth', 'gender', 'nationality']);

        $this->assertSame('verified', $user->fresh()->kyc_status->value);
        $this->assertDatabaseHas('identity_verifications', ['user_id' => $user->id, 'id_number_last4' => '8901']);
        $this->assertDatabaseMissing('identity_verifications', ['id_number_last4' => '12345678901']);

        $this->putJson('/api/v1/profile/personal', ['state_of_origin' => 'Rivers', 'lga' => 'Etche'])
            ->assertOk()->assertJsonPath('data.onboarding_step', 'contact');

        $this->putJson('/api/v1/profile/contact', ['country' => 'Nigeria', 'state' => 'Lagos', 'address' => '1 Test Road, Lekki', 'phone' => '+2348031234567', 'alt_phone' => null])
            ->assertOk()->assertJsonPath('data.onboarding_step', 'next_of_kin');

        $this->putJson('/api/v1/profile/next-of-kin', ['full_name' => 'Jane Doe', 'relationship' => 'Sister', 'phone' => '+2348030000000', 'email' => null])
            ->assertOk()
            ->assertJsonPath('data.onboarding_step', 'completed')
            ->assertJsonPath('data.user.profile_completed', true)
            ->assertJsonPath('data.next_of_kin.full_name', 'Jane Doe');

        Notification::assertSentTo($user, UserEvent::class, fn ($n) => $n->event === 'identity_verified');
        Notification::assertSentTo($user, UserEvent::class, fn ($n) => $n->event === 'profile_completed');
    }

    public function test_failed_identity_verification_returns_contract_error(): void
    {
        Notification::fake();
        $this->actingAsUser();

        $this->assertApiError(
            $this->postJson('/api/v1/profile/identity/verify', ['id_type' => 'nin', 'id_number' => '12345670000']),
            422,
            'identity_verification_failed',
        );
    }

    public function test_verified_name_fields_are_locked(): void
    {
        Notification::fake();
        $this->actingAsUser();
        $this->postJson('/api/v1/profile/identity/verify', ['id_type' => 'bvn', 'id_number' => '22345678901'])->assertOk();

        $response = $this->putJson('/api/v1/profile', ['first_name' => 'Changed', 'phone' => '+2348031234567']);

        $this->assertApiError($response, 422, 'field_locked');
        $response->assertJsonValidationErrors('first_name');
    }

    public function test_avatar_upload_goes_to_cloudinary_and_returns_media(): void
    {
        $this->fakeCloudinary(800, 800);
        $user = $this->actingAsUser();

        $response = $this->post('/api/v1/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('me.jpg', 800, 800)->size(300),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.collection', 'avatar')
            ->assertJsonPath('data.width', 800)
            ->assertJsonPath('data.dominant_color', '#5b7a3c');

        $this->assertStringContainsString('c_fill,g_face,w_400,h_400,f_auto,q_auto', $response->json('data.url'));
        $this->assertNotNull($user->fresh()->avatar_media_id);

        Http::assertSent(function ($request) use ($user) {
            $fields = collect($request->data())->pluck('contents', 'name');

            return str_contains($request->url(), 'api.cloudinary.com/v1_1/amcom-test/image/upload')
                && $fields['folder'] === 'amcom/avatars/'.$user->id
                && $fields['api_key'] === 'test-key'
                && strlen((string) $fields['signature']) === 40
                && ! $fields->has('api_secret');
        });
    }

    public function test_avatar_rejects_non_images_and_oversized_files(): void
    {
        $this->actingAsUser();

        $this->post('/api/v1/profile/avatar', ['avatar' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf')])
            ->assertStatus(422)->assertJsonValidationErrors('avatar');

        $this->post('/api/v1/profile/avatar', ['avatar' => UploadedFile::fake()->image('big.jpg', 800, 800)->size(3000)])
            ->assertStatus(422)->assertJsonValidationErrors('avatar');
    }
}
