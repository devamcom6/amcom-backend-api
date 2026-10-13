<?php

namespace App\Http\Resources;

use App\Models\Investment;
use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Investment — BuybackOrder (§6.5) or CapitalInvestmentOrder (§7.3), by product. */
class InvestmentOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $base = [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status->value,
        ];

        $quoteCommon = [
            'tenure_months' => $this->tenure_months,
            'roi_percent' => $this->roi_percent,
            'projected_return' => $this->projected_return,
            'profit' => $this->profit(),
            'maturity_date' => Format::ymd($this->maturity_date),
        ];

        if ($this->isBuyback()) {
            $plots = $this->plots;

            return $base + [
                'estate' => EstateReference::from($this->estate),
                'plots' => $plots->map(fn ($p) => ['id' => $p->id, 'code' => $p->code, 'size_sqm' => $p->size_sqm])->values()->all(),
                'quote' => ['plot_count' => $plots->count(), 'total_price' => $this->principal] + $quoteCommon,
                'agreement_signed_at' => Format::iso($this->agreement_signed_at),
                'created_at' => Format::iso($this->created_at),
            ];
        }

        return $base + [
            'quote' => ['amount' => $this->principal] + $quoteCommon,
            'agreement_signed_at' => Format::iso($this->agreement_signed_at),
            'created_at' => Format::iso($this->created_at),
        ];
    }
}
