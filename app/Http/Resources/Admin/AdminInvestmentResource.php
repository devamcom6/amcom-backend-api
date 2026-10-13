<?php

namespace App\Http\Resources\Admin;

use App\Models\Investment;
use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Investment — contract §15.5 */
class AdminInvestmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $description = $this->isBuyback()
            ? $this->estate?->name.' — '.$this->plots->count().' '.($this->plots->count() === 1 ? 'plot' : 'plots')
            : 'Capital investment — '.$this->planName();

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'product' => $this->product->value,
            'customer' => $this->user ? ['id' => $this->user->id, 'name' => $this->user->displayName(), 'email' => $this->user->email] : null,
            'description' => $description,
            'principal' => $this->principal,
            'roi_percent' => $this->roi_percent,
            'tenure_months' => $this->tenure_months,
            'status' => $this->status->value,
            'maturity_date' => Format::ymd($this->maturity_date),
            'payout_requested_at' => Format::iso($this->payout_requested_at),
            'created_at' => Format::iso($this->created_at),
        ];
    }
}
