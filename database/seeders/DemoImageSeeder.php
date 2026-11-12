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
 * Uploads the demo images in database/seeders/images to Cloudinary and attaches them to estates
 * and properties that have none. Idempotent; requires Cloudinary credentials. Never runs in production.
 *
 * The images live here (committed) rather than in docs/, which is git-ignored and so never deployed.
 *
 *   php artisan db:seed --class=DemoImageSeeder
 */
class DemoImageSeeder extends Seeder
{
    private const DIRECTORY = 'database/seeders/images';

    private const IMAGES = [
        'demo-1.png', 'demo-2.png', 'demo-3.png', 'demo-4.png',
        'demo-5.png', 'demo-6.png', 'demo-7.png', 'demo-8.png',
    ];

    /** @var list<string> */
    private array $files = [];

    private int $next = 0;

    public function run(MediaService $media, CloudinaryService $cloudinary): void
    {
        if (app()->isProduction()) {
            $this->command->warn('APP_ENV is production; skipping demo images.');

            return;
        }

        if (! $cloudinary->isConfigured()) {
            $this->command->warn('Cloudinary is not configured; skipping demo images.');

            return;
        }

        $this->files = array_values(array_filter(array_map(fn ($f) => base_path(self::DIRECTORY.'/'.$f), self::IMAGES), 'is_file'));

        if ($this->files === []) {
            $this->command->warn('No images found in '.self::DIRECTORY.'; skipping.');

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
