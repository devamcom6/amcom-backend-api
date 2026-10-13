<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\MediaCollection;
use App\Http\Controllers\Controller;
use App\Http\Resources\MediaResource;
use App\Models\Media;
use App\Support\ApiResponse;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /admin/media (contract §15.9) — media.manage */
class MediaLibraryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);

        $query = Media::query()
            ->when($list->filter('collection', MediaCollection::values()), fn ($q, $v) => $q->whereIn('collection', $v))
            ->when($list->search(), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('alt_text', 'like', "%{$term}%")
                ->orWhere('original_filename', 'like', "%{$term}%")
                ->orWhere('public_id', 'like', "%{$term}%")))
            ->latest('id');

        return ApiResponse::paginated($list->paginate($query, 12), MediaResource::class);
    }
}
