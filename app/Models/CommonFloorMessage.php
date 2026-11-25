<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommonFloorMessage extends Model
{
    protected $fillable = ['user_id', 'message', 'flagged', 'muted'];

    protected function casts(): array
    {
        return ['flagged' => 'boolean', 'muted' => 'boolean'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeVisible(Builder $q): Builder
    {
        return $q->where('muted', false);
    }
}
