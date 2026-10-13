<?php

namespace App\Models;

use App\Enums\FaqProduct;
use Illuminate\Database\Eloquent\Model;

class Faq extends Model
{
    protected $fillable = ['product', 'question', 'answer', 'position', 'is_published'];

    protected function casts(): array
    {
        return ['product' => FaqProduct::class, 'is_published' => 'boolean', 'position' => 'integer'];
    }
}
