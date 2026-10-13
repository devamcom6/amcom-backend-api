<?php

namespace Tests\Feature\Integrations;

use App\Enums\MediaCollection;
use App\Exceptions\ExternalServiceException;
use App\Models\Media;
use App\Services\Media\CloudinaryService;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CloudinaryServiceTest extends TestCase
{
    public function test_signature_follows_cloudinary_algorithm(): void
    {
        $service = new CloudinaryService(['api_secret' => 'abcd', 'cloud_name' => 'demo', 'api_key' => 'k']);

        // sha1("public_id=sample_image&timestamp=1315060510" + "abcd")
        $this->assertSame(sha1('public_id=sample_image&timestamp=1315060510abcd'), $service->sign(['timestamp' => '1315060510', 'public_id' => 'sample_image']));
    }

    public function test_delivery_urls_use_auto_format_quality_and_never_upscale(): void
    {
        $service = app(CloudinaryService::class);
        $media = new Media(['public_id' => 'amcom/estates/1/aerial', 'version' => 17, 'format' => 'jpg', 'width' => 3000, 'height' => 2000, 'collection' => MediaCollection::EstateGallery]);

        $urls = $service->deliveryUrls($media);

        $this->assertSame(['480', '960', '1440', '1920'], array_map('strval', array_keys($urls['variants'])));
        $this->assertSame('https://res.cloudinary.com/amcom-test/image/upload/f_auto,q_auto,c_limit,w_1920/v17/amcom/estates/1/aerial.jpg', $urls['url']);
        $this->assertStringContainsString('c_fill,g_auto,w_320,h_320', $urls['thumbnail_url']);

        $small = new Media(['public_id' => 'x', 'format' => 'png', 'width' => 600, 'height' => 400, 'collection' => MediaCollection::ListingPhoto]);
        $this->assertSame([480, 600], array_keys($service->deliveryUrls($small)['variants']));
    }

    public function test_unconfigured_cloudinary_fails_safely(): void
    {
        $service = new CloudinaryService(['cloud_name' => null, 'api_key' => null, 'api_secret' => null]);

        $this->assertFalse($service->isConfigured());
        $this->expectException(ExternalServiceException::class);
        $service->upload(UploadedFile::fake()->image('a.jpg'), 'amcom/test');
    }
}
