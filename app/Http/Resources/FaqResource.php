<?php

namespace App\Http\Resources;

use App\Models\Faq;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Faq — contract §13.2 */
class FaqResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product' => $this->product->value,
            'question' => $this->question,
            'answer' => $this->answer,
            'position' => $this->position,
            'is_published' => $this->is_published,
        ];
    }
}
