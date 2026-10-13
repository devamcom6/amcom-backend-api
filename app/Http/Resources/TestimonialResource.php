<?php

namespace App\Http\Resources;

use App\Models\Testimonial;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Testimonial — contract §13.1 */
class TestimonialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quote' => $this->quote,
            'author_name' => $this->author_name,
            'author_title' => $this->author_title,
            'avatar' => $this->avatar ? new MediaResource($this->avatar) : null,
            'is_published' => $this->is_published,
        ];
    }
}
