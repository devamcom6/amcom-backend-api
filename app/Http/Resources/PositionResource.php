<?php

namespace App\Http\Resources;

use App\Models\Investment;
use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Investment — BuybackPosition (§6.8) or CapitalPosition (§7.5), by product. */
class PositionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $common = [
            'status' => $this->status->positionStatus(),
            'principal' => $this->principal,
            'roi_percent' => $this->roi_percent,
            'projected_return' => $this->projected_return,
            'total_payout' => $this->projected_return,
            'started_at' => Format::ymd($this->started_at),
            'maturity_date' => Format::ymd($this->maturity_date),
            'tenure_months' => $this->tenure_months,
            'months_elapsed' => $this->monthsElapsed(),
            'days_to_maturity' => $this->daysToMaturity(),
            'documents' => DocumentResource::collection($this->documents)->resolve($request),
            'can_request_payout' => $this->canRequestPayout(),
            'can_reinvest' => $this->canReinvest(),
        ];

        if ($this->isBuyback()) {
            return [
                'id' => $this->id,
                'reference' => $this->reference,
                'estate' => EstateReference::from($this->estate),
                'plot_codes' => $this->plots->pluck('code')->values()->all(),
                'size_sqm' => (int) $this->plots->sum('size_sqm'),
            ] + $common;
        }

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'plan_name' => $this->planName(),
            'payout_requested_at' => Format::iso($this->payout_requested_at),
        ] + $common;
    }
}
