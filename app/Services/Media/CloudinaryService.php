<?php

namespace App\Services\Media;

use App\Enums\MediaCollection;
use App\Exceptions\ExternalServiceException;
use App\Models\Media;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The only class that talks to Cloudinary. Uploads are signed server-side with the API secret,
 * which never leaves the backend. Delivery URLs are built from stored metadata.
 */
class CloudinaryService
{
    public function __construct(private readonly array $config) {}

    public function isConfigured(): bool
    {
        return filled($this->config['cloud_name'] ?? null)
            && filled($this->config['api_key'] ?? null)
            && filled($this->config['api_secret'] ?? null);
    }

    /**
     * Signed upload of an image. Returns normalised metadata.
     *
     * @return array{public_id: string, version: int|null, secure_url: string, format: string|null, width: int|null, height: int|null, bytes: int|null, dominant_color: string|null}
     */
    public function upload(UploadedFile $file, string $folder, ?string $publicId = null): array
    {
        $this->ensureConfigured();

        $params = [
            'folder' => $folder,
            'public_id' => $publicId ?? (string) Str::uuid(),
            'overwrite' => 'false',
            'unique_filename' => 'false',
            'colors' => 'true',
            'timestamp' => (string) time(),
        ];

        try {
            $response = Http::timeout($this->config['timeout'] ?? 60)
                ->attach('file', fopen($file->getRealPath(), 'r'), 'upload.'.($file->guessExtension() ?: 'jpg'))
                ->post($this->endpoint('image/upload'), $params + [
                    'api_key' => $this->config['api_key'],
                    'signature' => $this->sign($params),
                ]);
        } catch (ConnectionException $e) {
            Log::error('cloudinary.upload.connection_failed', ['folder' => $folder, 'error' => $e->getMessage()]);
            throw new ExternalServiceException('cloudinary', 'Image upload is temporarily unavailable. Please try again.');
        }

        if ($response->failed()) {
            Log::error('cloudinary.upload.failed', [
                'folder' => $folder,
                'status' => $response->status(),
                'error' => $response->json('error.message'),
            ]);

            // 4xx from Cloudinary usually means the file itself was rejected.
            if ($response->status() === 400) {
                throw new ExternalServiceException('cloudinary', 'This image could not be processed. Please try a different file.', 422, 'validation_failed');
            }

            throw new ExternalServiceException('cloudinary', 'Image upload is temporarily unavailable. Please try again.');
        }

        $data = $response->json();

        return [
            'public_id' => (string) $data['public_id'],
            'version' => isset($data['version']) ? (int) $data['version'] : null,
            'secure_url' => (string) $data['secure_url'],
            'format' => $data['format'] ?? null,
            'width' => isset($data['width']) ? (int) $data['width'] : null,
            'height' => isset($data['height']) ? (int) $data['height'] : null,
            'bytes' => isset($data['bytes']) ? (int) $data['bytes'] : null,
            'dominant_color' => $this->dominantColor($data),
        ];
    }

    /** Permanently removes an asset (and its derived versions from the CDN cache). */
    public function destroy(string $publicId): bool
    {
        $this->ensureConfigured();

        $params = ['public_id' => $publicId, 'invalidate' => 'true', 'timestamp' => (string) time()];

        try {
            $response = Http::timeout($this->config['timeout'] ?? 60)->asForm()->post($this->endpoint('image/destroy'), $params + [
                'api_key' => $this->config['api_key'],
                'signature' => $this->sign($params),
            ]);
        } catch (ConnectionException $e) {
            Log::warning('cloudinary.destroy.connection_failed', ['public_id' => $publicId, 'error' => $e->getMessage()]);

            return false;
        }

        $result = $response->json('result');

        if ($response->failed() || ! in_array($result, ['ok', 'not found'], true)) {
            Log::warning('cloudinary.destroy.failed', ['public_id' => $publicId, 'status' => $response->status(), 'result' => $result]);

            return false;
        }

        return true;
    }

    // ------------------------------------------------------------------ delivery URLs

    public function url(Media $media, string $transformation): string
    {
        $version = $media->version ? 'v'.$media->version.'/' : '';
        $extension = $media->format ? '.'.$media->format : '';

        return sprintf(
            '%s/%s/image/upload/%s/%s%s%s',
            rtrim($this->config['delivery_url'] ?? 'https://res.cloudinary.com', '/'),
            $this->config['cloud_name'] ?? 'demo',
            $transformation,
            $version,
            $media->public_id,
            $extension,
        );
    }

    /**
     * Frontend `Media` payload pieces (contract §3.2): main URL, thumbnail and responsive variants.
     * `c_limit` never upscales; widths above the original are dropped.
     *
     * @return array{url: string, thumbnail_url: string, variants: array<int, string>}
     */
    public function deliveryUrls(Media $media): array
    {
        $thumb = (int) config('amcom.media.thumbnail_size');

        if ($media->collection === MediaCollection::Avatar) {
            $size = (int) config('amcom.media.avatar_size');
            $square = fn (int $s) => $this->url($media, "c_fill,g_face,w_{$s},h_{$s},f_auto,q_auto");

            return [
                'url' => $square($size),
                'thumbnail_url' => $square(160),
                'variants' => [160 => $square(160), $size => $square($size)],
            ];
        }

        $widths = in_array($media->collection, [MediaCollection::EstateGallery, MediaCollection::Content], true)
            ? config('amcom.media.hero_variant_widths')
            : config('amcom.media.variant_widths');

        $original = $media->width ?: max($widths);
        $usable = array_values(array_filter($widths, fn (int $w) => $w <= $original));

        if ($usable === [] || ($original < max($widths) && ! in_array($original, $usable, true))) {
            $usable[] = $original; // include the intrinsic width so the best quality is reachable
        }

        $variants = [];
        foreach ($usable as $w) {
            $variants[$w] = $this->url($media, "f_auto,q_auto,c_limit,w_{$w}");
        }

        return [
            'url' => end($variants),
            'thumbnail_url' => $this->url($media, "f_auto,q_auto,c_fill,g_auto,w_{$thumb},h_{$thumb}"),
            'variants' => $variants,
        ];
    }

    public function folder(string ...$segments): string
    {
        return implode('/', array_filter([trim((string) config('amcom.media.root_folder'), '/'), ...$segments]));
    }

    // ------------------------------------------------------------------ internals

    /** Cloudinary signature: SHA-1 of the alphabetically sorted params + API secret. */
    public function sign(array $params): string
    {
        ksort($params);
        $toSign = collect($params)->map(fn ($v, $k) => $k.'='.$v)->implode('&');

        return sha1($toSign.$this->config['api_secret']);
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->config['upload_url'] ?? 'https://api.cloudinary.com/v1_1', '/').'/'.$this->config['cloud_name'].'/'.$path;
    }

    private function dominantColor(array $data): ?string
    {
        $color = $data['colors'][0][0] ?? ($data['predominant']['google'][0][0] ?? null);

        return is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : null;
    }

    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            Log::error('cloudinary.not_configured');
            throw new ExternalServiceException('cloudinary', 'Image upload is not available right now.');
        }
    }
}
