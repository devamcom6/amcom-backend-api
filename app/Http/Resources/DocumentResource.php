<?php

namespace App\Http\Resources;

use App\Models\Document;
use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Document — contract §3.3. Never includes a download URL. */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type->value,
            'size_bytes' => $this->size_bytes,
            'uploaded_at' => Format::iso($this->updated_at ?? $this->created_at),
            'status' => $this->status->value,
        ];
    }
}
