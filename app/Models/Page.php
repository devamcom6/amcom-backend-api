<?php

namespace App\Models;

use App\Support\HtmlSanitizer;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    protected $fillable = ['slug', 'title', 'body_html'];

    /** CMS HTML is sanitised on save (contract §13.3); the frontend sanitises again. */
    /** @return Attribute<string, string|null> */
    protected function bodyHtml(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => (string) $value,
            set: fn (?string $value) => HtmlSanitizer::clean((string) $value),
        );
    }
}
