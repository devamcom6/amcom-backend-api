<?php

namespace Tests;

use App\Models\User;
use App\Models\UserProfile;
use App\Services\Mail\ZeptoMailService;
use App\Services\Media\CloudinaryService;
use App\Services\Payments\PaystackService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        // External providers always point at fake credentials; real HTTP is blocked.
        config([
            'services.cloudinary.cloud_name' => 'amcom-test',
            'services.cloudinary.api_key' => 'test-key',
            'services.cloudinary.api_secret' => 'test-secret',
            'services.paystack.secret_key' => 'sk_test_secret',
            'services.paystack.webhook_ips' => [],
            'services.zeptomail.api_key' => 'zepto-test-key',
            'services.identity.driver' => 'fake',
            'services.sms.driver' => 'log',
            'amcom.frontend_url' => 'https://app.amcom.test',
            'amcom.frontend_urls' => ['https://app.amcom.test'],
        ]);

        $this->app->forgetInstance(CloudinaryService::class);
        $this->app->forgetInstance(PaystackService::class);
        $this->app->forgetInstance(ZeptoMailService::class);

        Http::preventStrayRequests();
        $this->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);
    }

    protected function customer(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->profile()->save(new UserProfile);

        return $user;
    }

    protected function admin(array $attributes = []): User
    {
        return User::factory()->admin()->create($attributes);
    }

    protected function superAdmin(array $attributes = []): User
    {
        return User::factory()->superAdmin()->create($attributes);
    }

    protected function actingAsUser(?User $user = null): User
    {
        $user ??= $this->customer();
        Sanctum::actingAs($user);

        return $user;
    }

    /** Asserts the contract error envelope (§1.4) with the expected code. */
    protected function assertApiError($response, int $status, string $code): void
    {
        $response->assertStatus($status)->assertJson(['success' => false, 'code' => $code]);
    }

    /** Standard successful Cloudinary upload response. */
    protected function fakeCloudinary(int $width = 1920, int $height = 1080): void
    {
        Http::fake([
            'api.cloudinary.com/*/image/upload' => fn ($request) => Http::response([
                'public_id' => 'amcom/test/'.uniqid(),
                'version' => 1700000000,
                'secure_url' => 'https://res.cloudinary.com/amcom-test/image/upload/v1700000000/test.jpg',
                'format' => 'jpg',
                'width' => $width,
                'height' => $height,
                'bytes' => 123456,
                'colors' => [['#5B7A3C', 40.1]],
            ]),
            'api.cloudinary.com/*/image/destroy' => Http::response(['result' => 'ok']),
        ]);
    }
}
