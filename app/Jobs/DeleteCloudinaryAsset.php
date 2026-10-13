<?php

namespace App\Jobs;

use App\Services\Media\CloudinaryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DeleteCloudinaryAsset implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public readonly string $publicId) {}

    public function handle(CloudinaryService $cloudinary): void
    {
        if (! $cloudinary->isConfigured()) {
            Log::warning('cloudinary.destroy.skipped_not_configured', ['public_id' => $this->publicId]);

            return;
        }

        if (! $cloudinary->destroy($this->publicId)) {
            throw new RuntimeException('Cloudinary asset deletion failed; will retry.');
        }
    }

    public function failed(?\Throwable $e): void
    {
        Log::error('cloudinary.destroy.gave_up', ['public_id' => $this->publicId, 'error' => $e?->getMessage()]);
    }
}
