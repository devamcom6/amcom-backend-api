<?php

namespace App\Http\Resources;

use App\Models\LoginActivity;
use App\Support\Format;
use App\Support\UserAgent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @mixin LoginActivity — contract §5.10.
 * Expects `token_alive` to be set by the controller (one query for the whole list).
 */
class LoginActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $current = $request->user()?->currentAccessToken();
        $currentId = $current instanceof PersonalAccessToken ? $current->id : null;

        return [
            'id' => $this->id,
            'date' => Format::ymd($this->created_at),
            'device' => $this->device ?? 'Unknown device',
            'location' => $this->location ?? 'Unknown location',
            'ip_address' => UserAgent::maskIp($this->ip_address),
            'status' => match (true) {
                $currentId !== null && $this->token_id === $currentId => 'current',
                $this->signed_out_at === null && (bool) $this->getAttribute('token_alive') => 'active',
                default => 'signed_out',
            },
        ];
    }
}
