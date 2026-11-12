<?php

namespace App\Http\Resources\Admin;

use App\Enums\RequestKind;
use App\Models\ServiceRequest;
use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ServiceRequest — contract §15.7 */
class AdminRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'reference' => $this->reference,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'referrer_name' => $this->hasReferrer() ? $this->referrer_name : null,
            'referrer_phone' => $this->hasReferrer() ? $this->referrer_phone : null,
            'referrer_user' => $this->hasReferrer() && $this->referrer ? ['id' => $this->referrer->id, 'name' => $this->referrer->displayName()] : null,
            'subject' => $this->subject_line,
            'message' => $this->message,
            'product' => $this->product?->value,
            'details' => $this->meta ?? (object) [],
            'status' => $this->status->value,
            'assigned_to' => $this->assignee ? ['id' => $this->assignee->id, 'name' => $this->assignee->displayName()] : null,
            'scheduled_for' => Format::iso($this->scheduled_for),
            'created_at' => Format::iso($this->created_at),
        ];
    }

    /** Referrer fields are only collected on interest and application forms. */
    private function hasReferrer(): bool
    {
        return in_array($this->kind, [RequestKind::Interest, RequestKind::Application], true);
    }
}
