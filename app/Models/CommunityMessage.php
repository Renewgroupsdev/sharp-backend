<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunityMessage extends Model
{
    protected $fillable = ['group_id', 'user_id', 'body', 'removed_by', 'removed_at'];

    protected function casts(): array
    {
        return ['removed_at' => 'datetime'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(CommunityGroup::class, 'group_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeVisible(Builder $q): Builder
    {
        return $q->whereNull('removed_at');
    }
}
