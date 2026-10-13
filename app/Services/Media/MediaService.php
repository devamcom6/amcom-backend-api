<?php

namespace App\Services\Media;

use App\Enums\MediaCollection;
use App\Jobs\DeleteCloudinaryAsset;
use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Media lifecycle on top of CloudinaryService: upload → persist metadata,
 * safe replacement (new first, old removed only after commit) and deletion.
 */
class MediaService
{
    public function __construct(private readonly CloudinaryService $cloudinary) {}

    public function upload(
        UploadedFile $file,
        MediaCollection $collection,
        string $folder,
        ?Model $owner = null,
        ?User $creator = null,
        ?string $altText = null,
        int $position = 0,
    ): Media {
        $uploaded = $this->cloudinary->upload($file, $folder);

        try {
            $media = new Media([
                'collection' => $collection,
                'provider' => 'cloudinary',
                'public_id' => $uploaded['public_id'],
                'folder' => $folder,
                'version' => $uploaded['version'],
                'original_filename' => mb_substr(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 0, 200),
                'secure_url' => $uploaded['secure_url'],
                'format' => $uploaded['format'],
                'mime_type' => $file->getMimeType(), // sniffed from content, not the browser header
                'width' => $uploaded['width'],
                'height' => $uploaded['height'],
                'bytes' => $uploaded['bytes'] ?? $file->getSize(),
                'alt_text' => $altText,
                'dominant_color' => $uploaded['dominant_color'],
                'position' => $position,
                'created_by' => $creator?->id,
            ]);

            if ($owner !== null) {
                $media->owner()->associate($owner);
            }

            $media->save();

            return $media;
        } catch (Throwable $e) {
            // Compensate: never leave an orphaned asset if the metadata write fails.
            $this->cloudinary->destroy($uploaded['public_id']);
            throw $e;
        }
    }

    /** Replaces a user's avatar; the old asset is removed only after the new one is committed. */
    public function replaceAvatar(User $user, UploadedFile $file): Media
    {
        $previous = $user->avatar;

        $media = $this->upload(
            $file,
            MediaCollection::Avatar,
            $this->cloudinary->folder('avatars', (string) $user->id),
            $user,
            $user,
            $user->displayName().' profile photo',
        );

        DB::transaction(function () use ($user, $media) {
            $user->forceFill(['avatar_media_id' => $media->id])->save();
        });

        if ($previous !== null) {
            $this->delete($previous);
        }

        return $media;
    }

    /** Deletes the record now and the Cloudinary asset asynchronously (retried on failure). */
    public function delete(Media $media): void
    {
        $publicId = $media->public_id;

        DB::transaction(fn () => $media->delete());

        DeleteCloudinaryAsset::dispatch($publicId)->afterCommit();

        Log::info('media.deleted', ['media_id' => $media->id, 'public_id' => $publicId]);
    }

    public function urls(Media $media): array
    {
        return $this->cloudinary->deliveryUrls($media);
    }

    public function folder(string ...$segments): string
    {
        return $this->cloudinary->folder(...$segments);
    }
}
