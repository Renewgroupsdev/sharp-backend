<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One event photo or video of the Gallery. The file is private and is opened through a signed link. */
class GalleryItem extends Model
{
    protected $fillable = ['title', 'description', 'event', 'event_date', 'type', 'file_path', 'file_name', 'mime', 'size', 'uploaded_by'];

    protected function casts(): array
    {
        return ['event_date' => 'date', 'size' => 'integer'];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
