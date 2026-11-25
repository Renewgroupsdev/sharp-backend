<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Models\Concerns\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseCategory extends Model
{
    use Searchable;

    protected $fillable = ['name', 'description', 'status'];

    protected function casts(): array
    {
        return ['status' => RecordStatus::class];
    }

    public static function searchableColumns(): array
    {
        return ['name', 'description'];
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }
}
