<?php

namespace App\Http\Resources;

use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/** @mixin DatabaseNotification — contract §12 */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->data ?? [];

        return [
            'id' => $this->id,
            'category' => $data['category'] ?? 'system',
            'title' => $data['title'] ?? '',
            'body' => $data['body'] ?? '',
            'action_url' => $data['action_url'] ?? null,
            'read_at' => Format::iso($this->read_at),
            'created_at' => Format::iso($this->created_at),
        ];
    }
}
