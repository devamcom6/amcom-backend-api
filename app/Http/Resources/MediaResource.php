<?php

namespace App\Http\Resources;

use App\Models\Media;
use App\Services\Media\CloudinaryService;
use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Media — contract §3.2 */
class MediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $urls = app(CloudinaryService::class)->deliveryUrls($this->resource);

        return [
            'id' => $this->id,
            'url' => $urls['url'],
            'thumbnail_url' => $urls['thumbnail_url'],
            'width' => $this->width,
            'height' => $this->height,
            'format' => $this->format,
            'alt_text' => $this->alt_text ?? '',
            // Object, not array: JsonResource re-indexes numeric-keyed arrays, which would turn
            // the width → URL map ({"480": …}) into a list.
            'variants' => (object) $urls['variants'],
            'dominant_color' => $this->dominant_color,
            'size_bytes' => $this->bytes,
            'mime_type' => $this->mime_type,
            'collection' => $this->collection->value,
            'created_at' => Format::iso($this->created_at),
        ];
    }
}
