<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User — contract §3.1. Role and permissions always come from the database. */
class AuthUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar' => $this->avatar ? new MediaResource($this->avatar) : null,
            'role' => $this->role,
            'permissions' => $this->permissionKeys(),
            'status' => $this->status->value,
            'email_verified_at' => Format::iso($this->email_verified_at),
            'kyc_status' => $this->kyc_status->value,
            'profile_completed' => $this->profile_completed_at !== null,
            'two_factor_enabled' => (bool) $this->two_factor_enabled,
            'created_at' => Format::iso($this->created_at),
        ];
    }
}
