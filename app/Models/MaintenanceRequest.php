<?php

namespace App\Models;

use App\Enums\MaintenanceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceRequest extends Model
{
    protected $fillable = ['user_id', 'title', 'description', 'status'];

    protected function casts(): array
    {
        return ['status' => MaintenanceStatus::class];
    }

    /** @return BelongsTo<PropertyHolding, $this> */
    public function holding(): BelongsTo
    {
        return $this->belongsTo(PropertyHolding::class, 'property_holding_id');
    }
}
