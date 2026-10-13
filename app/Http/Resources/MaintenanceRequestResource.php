<?php

namespace App\Http\Resources;

use App\Models\MaintenanceRequest;
use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MaintenanceRequest */
class MaintenanceRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'created_at' => Format::ymd($this->created_at),
        ];
    }
}
