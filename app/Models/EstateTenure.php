<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstateTenure extends Model
{
    protected $fillable = ['months', 'roi_percent'];

    protected function casts(): array
    {
        return ['months' => 'integer', 'roi_percent' => 'float'];
    }

    /** @return BelongsTo<Estate, $this> */
    public function estate(): BelongsTo
    {
        return $this->belongsTo(Estate::class);
    }
}
