<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MediaCollection;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\UploadMediaRequest;
use App\Http\Resources\MediaResource;
use App\Models\Media;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Media\MediaService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Contract §14.2–§14.3 — media library (customer uploads use their dedicated endpoints). */
class MediaController extends Controller
{
    public function __construct(
        private readonly MediaService $media,
        private readonly AuditLogger $audit,
    ) {}

    public function store(UploadMediaRequest $request): JsonResponse
    {
        if (! $request->user()->hasPermission('media.manage')) {
            throw ApiException::forbidden();
        }

        $collection = MediaCollection::from($request->validated('collection'));

        $media = $this->media->upload(
            $request->file('file'),
            $collection,
            $this->media->folder(match ($collection) {
                MediaCollection::EstateGallery => 'estates',
                MediaCollection::PropertyGallery => 'properties',
                default => 'content',
            }),
            null,
            $request->user(),
            $request->validated('alt_text'),
        );

        $this->audit->log('media.uploaded', $media, null, ['collection' => $collection->value, 'public_id' => $media->public_id], 'uploaded media');

        return ApiResponse::created(new MediaResource($media), 'Image uploaded successfully.');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $media = $this->authorizedMedia($request->user(), $id);
        $validated = $request->validate(['alt_text' => ['required', 'string', 'max:255']]);

        $media->forceFill(['alt_text' => $validated['alt_text']])->save();

        return ApiResponse::success(new MediaResource($media), 'Media updated.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $media = $this->authorizedMedia($request->user(), $id);

        $this->audit->log('media.deleted', $media, ['public_id' => $media->public_id, 'collection' => $media->collection->value], null, 'deleted media');
        $this->media->delete($media);

        return ApiResponse::success(null, 'Media deleted.');
    }

    /** Library managers can edit anything; customers only the photos they uploaded. 404 otherwise. */
    private function authorizedMedia(User $user, int $id): Media
    {
        $media = Media::query()->findOrFail($id);

        if ($user->hasPermission('media.manage')) {
            return $media;
        }

        $ownsIt = $media->created_by === $user->id
            && in_array($media->collection, [MediaCollection::ListingPhoto, MediaCollection::Avatar], true);

        if (! $ownsIt) {
            throw ApiException::notFound();
        }

        return $media;
    }
}
