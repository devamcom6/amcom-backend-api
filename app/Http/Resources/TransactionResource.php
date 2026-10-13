<?php

namespace App\Http\Resources;

use App\Models\Transaction;
use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Transaction — contract §11.5 */
class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'user' => $this->user ? ['id' => $this->user->id, 'name' => $this->user->displayName(), 'email' => $this->user->email] : null,
            'payable_type' => $this->payable_type->value,
            'payable_id' => $this->payable_id,
            'description' => $this->description,
            'amount' => $this->amount,
            'fee' => $this->fee,
            'currency' => $this->currency,
            'method' => $this->method->value,
            'status' => $this->status->value,
            'channel' => $this->channel,
            'gateway_response' => $this->gateway_response,
            'paid_at' => Format::iso($this->paid_at),
            'created_at' => Format::iso($this->created_at),
        ];
    }
}
