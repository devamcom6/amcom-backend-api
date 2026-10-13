<?php

namespace App\Http\Resources;

use App\Models\PayoutAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PayoutAccount — contract §5.8. Only masked account numbers are ever returned. */
class PayoutAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bank_code' => $this->bank_code,
            'bank_name' => $this->bank_name,
            'account_number_masked' => $this->maskedNumber(),
            'account_name' => $this->account_name,
            'is_default' => (bool) $this->is_default,
        ];
    }
}
