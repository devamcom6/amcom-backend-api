<?php

namespace App\Http\Resources\Admin;

use App\Models\User;
use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User — AdminAccount (contract §15.11) */
class AdminAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name ?? $this->displayName(),
            'email' => $this->email,
            'role' => $this->role,
            'status' => $this->status->value,
            'last_login_at' => Format::iso($this->last_login_at),
            'created_at' => Format::iso($this->created_at),
        ];
    }
}
