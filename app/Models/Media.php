<?php

namespace App\Models;

use App\Enums\MediaCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Metadata for a Cloudinary asset. URLs/variants are derived at render time
 * by CloudinaryService, so changing transformation policy needs no data migration.
 */
class Media extends Model
{
    protected $table = 'media';

    protected $fillable = [
        'collection', 'provider', 'public_id', 'folder', 'version', 'original_filename', 'secure_url',
        'format', 'mime_type', 'width', 'height', 'bytes', 'alt_text', 'dominant_color', 'position', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'collection' => MediaCollection::class,
            'width' => 'integer',
            'height' => 'integer',
            'bytes' => 'integer',
            'version' => 'integer',
            'position' => 'integer',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
