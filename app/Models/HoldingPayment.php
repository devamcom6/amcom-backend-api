<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HoldingPayment extends Model
{
    protected $fillable = ['property_holding_id', 'transaction_id', 'date', 'description', 'amount', 'status'];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'integer'];
    }

    /** @return BelongsTo<PropertyHolding, $this> */
    public function holding(): BelongsTo
    {
        return $this->belongsTo(PropertyHolding::class, 'property_holding_id');
    }
}
