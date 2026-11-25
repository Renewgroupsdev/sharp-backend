<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/** Case-insensitive "contains" search across a model's own searchable columns. */
trait Searchable
{
    /** Columns a free-text search may match; defined by the model. */
    abstract public static function searchableColumns(): array;

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            foreach (static::searchableColumns() as $column) {
                $q->orWhereLike($column, "%{$term}%");
            }
        });
    }
}
