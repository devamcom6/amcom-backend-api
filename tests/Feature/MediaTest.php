<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Jobs\DeleteCloudinaryAsset;
use App\Models\Media;
use App\Models\Property;
use App\Services\Media\CloudinaryService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MediaTest extends TestCase
{
    private function draftListing($user): Property
    {
        return Property::factory()->status(ListingStatus::Draft)->create(['user_id' => $user->id]);
    }

    public function test_owner_uploads_listing_photos_with_responsive_variants(): void
    {
        $this->fakeCloudinary(1200, 800);
        $user = $this->actingAsUser();
        $listing = $this->draftListing($user);

        $response = $this->post("/api/v1/listings/{$listing->id}/photos", ['photo' => UploadedFile::fake()->image('room.jpg', 1200, 800)]);

        $response->assertCreated()
            ->assertJsonPath('data.collection', 'listing_photo')
            ->assertJsonPath('data.width', 1200)
            ->assertJsonPath('data.height', 800);

        // No upscaling: 1440 is above the 1200px original, so variants stop at 960 + the intrinsic width.
        $this->assertSame([480, 960, 1200], array_keys($response->json('data.variants')));
        $this->assertStringContainsString('f_auto,q_auto,c_limit,w_480', $response->json('data.variants.480'));
        $this->assertStringContainsString('c_fill,g_auto,w_320,h_320', $response->json('data.thumbnail_url'));
        $this->assertStringContainsString($listing->title, $response->json('data.alt_text'));
    }

    public function test_listing_photo_limit_is_enforced(): void
    {
        $this->fakeCloudinary();
        $user = $this->actingAsUser();
        $listing = $this->draftListing($user);

        foreach (range(1, 10) as $i) {
            Media::query()->forceCreate(['collection' => 'listing_photo', 'public_id' => "p{$i}", 'secure_url' => 'https://x', 'owner_type' => 'property', 'owner_id' => $listing->id]);
        }

        $this->post("/api/v1/listings/{$listing->id}/photos", ['photo' => UploadedFile::fake()->image('room.jpg', 1200, 800)])
            ->assertStatus(422)->assertJsonValidationErrors('photo');
    }

    public function test_invalid_listing_photo_is_rejected_before_cloudinary(): void
    {
        $user = $this->actingAsUser();
        $listing = $this->draftListing($user);

        $this->post("/api/v1/listings/{$listing->id}/photos", ['photo' => UploadedFile::fake()->create('evil.php.jpg', 10, 'text/x-php')])
            ->assertStatus(422)->assertJsonValidationErrors('photo');

        $this->post("/api/v1/listings/{$listing->id}/photos", ['photo' => UploadedFile::fake()->image('tiny.jpg', 50, 50)])
            ->assertStatus(422)->assertJsonValidationErrors('photo');

        Http::assertNothingSent();
    }

    public function test_cannot_upload_to_someone_elses_listing(): void
    {
        $listing = $this->draftListing($this->customer());
        $this->actingAsUser();

        $this->assertApiError(
            $this->post("/api/v1/listings/{$listing->id}/photos", ['photo' => UploadedFile::fake()->image('room.jpg', 1200, 800)]),
            404,
            'not_found',
        );
    }

    public function test_deleting_a_photo_removes_the_record_and_queues_cloudinary_cleanup(): void
    {
        Queue::fake();
        $user = $this->actingAsUser();
        $listing = $this->draftListing($user);
        $media = Media::query()->forceCreate(['collection' => 'listing_photo', 'public_id' => 'amcom/listings/x', 'secure_url' => 'https://x', 'owner_type' => 'property', 'owner_id' => $listing->id]);

        $this->deleteJson("/api/v1/listings/{$listing->id}/photos/{$media->id}")->assertOk()->assertJsonPath('data', null);

        $this->assertModelMissing($media);
        Queue::assertPushed(DeleteCloudinaryAsset::class, fn ($job) => $job->publicId === 'amcom/listings/x');
    }

    public function test_delete_job_calls_cloudinary_destroy(): void
    {
        $this->fakeCloudinary();

        (new DeleteCloudinaryAsset('amcom/listings/x'))->handle(app(CloudinaryService::class));

        Http::assertSent(fn ($r) => str_contains($r->url(), 'image/destroy') && $r['public_id'] === 'amcom/listings/x');
    }

    public function test_media_library_upload_requires_media_manage(): void
    {
        $this->fakeCloudinary();
        $this->actingAsUser();

        $this->assertApiError(
            $this->post('/api/v1/media', ['file' => UploadedFile::fake()->image('hero.jpg', 1920, 1080), 'collection' => 'content']),
            403,
            'forbidden',
        );

        Sanctum::actingAs($this->admin());

        $this->post('/api/v1/media', ['file' => UploadedFile::fake()->image('hero.jpg', 1920, 1080), 'collection' => 'content', 'alt_text' => 'Hero'])
            ->assertCreated()
            ->assertJsonPath('data.alt_text', 'Hero')
            ->assertJsonCount(4, 'data.variants'); // hero widths incl. 1920

        $this->getJson('/api/v1/admin/media')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_cloudinary_failure_returns_safe_error(): void
    {
        Http::fake(['api.cloudinary.com/*' => Http::response(['error' => ['message' => 'internal detail']], 500)]);
        $this->actingAsUser();

        $response = $this->post('/api/v1/profile/avatar', ['avatar' => UploadedFile::fake()->image('me.jpg', 400, 400)]);

        $this->assertApiError($response, 503, 'provider_unavailable');
        $this->assertStringNotContainsString('internal detail', $response->getContent());
    }
}
