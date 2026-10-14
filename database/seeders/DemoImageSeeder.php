<?php

namespace Database\Seeders;

use App\Enums\MediaCollection;
use App\Models\Estate;
use App\Models\Property;
use App\Services\Media\CloudinaryService;
use App\Services\Media\MediaService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Uploads the design images in docs/images to Cloudinary and attaches them to estates and
 * properties that have none. Idempotent; requires Cloudinary credentials. Never runs in production.
 *
 *   php artisan db:seed --class=DemoImageSeeder
 */
class DemoImageSeeder extends Seeder
{
    private const IMAGES = [
        'Aerial photo.png', 'Hero Image Container.png', 'CTA Image.png', 'Image Area.png',
        'Image Area (1).png', 'Image Area (2).png', 'Image.png', 'Image (1).png',
    ];

    /** @var list<string> */
    private array $files = [];

    private int $next = 0;

    public function run(MediaService $media, CloudinaryService $cloudinary): void
    {
        if (app()->isProduction()) {
            return;
        }

        if (! $cloudinary->isConfigured()) {
            $this->command->warn('Cloudinary is not configured; skipping demo images.');

            return;
        }

        $this->files = array_values(array_filter(array_map(fn ($f) => base_path('docs/images/'.$f), self::IMAGES), 'is_file'));

        if ($this->files === []) {
            $this->command->warn('No images found in docs/images; skipping.');

            return;
        }

        $uploaded = 0;

        foreach (Estate::query()->whereDoesntHave('gallery')->get() as $estate) {
            foreach (['Aerial view', 'Entrance gate', 'Road network'] as $label) {
                $uploaded += $this->attach($media, $estate, MediaCollection::EstateGallery, 'estates/'.$estate->id, "{$label} of {$estate->name}");
            }
        }

        foreach (Property::query()->whereDoesntHave('photos')->get() as $property) {
            $collection = $property->user_id ? MediaCollection::ListingPhoto : MediaCollection::PropertyGallery;
            $folder = ($property->user_id ? 'listings/' : 'properties/').$property->id;
            $uploaded += $this->attach($media, $property, $collection, $folder, $property->title.' — exterior');
        }

        $this->command->info("Uploaded {$uploaded} demo image(s) to Cloudinary.");
    }

    private function attach(MediaService $media, Model $owner, MediaCollection $collection, string $folder, string $alt): int
    {
        $path = $this->files[$this->next++ % count($this->files)];

        try {
            $media->upload(new UploadedFile($path, basename($path), 'image/png', null, true), $collection, $media->folder($folder), $owner, null, $alt);

            return 1;
        } catch (Throwable $e) {
            $this->command->warn('Image upload skipped: '.$e->getMessage());

            return 0;
        }
    }
}
