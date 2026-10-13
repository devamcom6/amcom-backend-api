<?php

namespace App\Http\Resources\Admin;

use App\Support\Format;
use Illuminate\Http\Request;

/** AdminUserDetail (contract §15.2). */
class AdminUserDetailResource extends AdminUserResource
{
    public function toArray(Request $request): array
    {
        $profile = $this->profile;

        return parent::toArray($request) + [
            'address' => $profile ? collect([$profile->address, $profile->city, $profile->state, $profile->country])->filter()->implode(', ') ?: null : null,
            'next_of_kin' => $profile?->hasNextOfKin() ? [
                'full_name' => $profile->next_of_kin_name,
                'relationship' => $profile->next_of_kin_relationship,
                'phone' => $profile->next_of_kin_phone,
            ] : null,
            'positions' => $this->investments
                ->sortByDesc('id')
                ->take(20)
                ->map(fn ($i) => [
                    'id' => $i->id,
                    'reference' => $i->reference,
                    'product' => $i->product->value,
                    'principal' => $i->principal,
                    'status' => $i->status->value,
                ])->values()->all(),
            'recent_transactions' => $this->transactions
                ->sortByDesc('id')
                ->take(10)
                ->map(fn ($t) => [
                    'reference' => $t->reference,
                    'amount' => $t->amount,
                    'status' => $t->status->value,
                    'date' => Format::iso($t->paid_at ?? $t->created_at),
                ])->values()->all(),
        ];
    }
}
