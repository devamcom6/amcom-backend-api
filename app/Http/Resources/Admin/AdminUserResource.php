<?php

namespace App\Http\Resources\Admin;

use App\Models\User;
use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User — AdminUser (contract §15.2). Expects investments_count / total_invested aggregates. */
class AdminUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $attributes = $this->resource->getAttributes();

        return [
            'id' => $this->id,
            'name' => $this->name ?? $this->displayName(),
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role,
            'status' => $this->status->value,
            'kyc_status' => $this->kyc_status->value,
            'email_verified' => $this->email_verified_at !== null,
            'investments_count' => (int) ($attributes['investments_count'] ?? 0),
            'total_invested' => (int) ($attributes['total_invested'] ?? 0),
            'last_login_at' => Format::iso($this->last_login_at),
            'created_at' => Format::iso($this->created_at),
        ];
    }
}
