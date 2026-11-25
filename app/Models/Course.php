<?php

namespace App\Models;

use App\Enums\CourseLevel;
use App\Enums\CourseMode;
use App\Enums\CourseScope;
use App\Enums\CourseStatus;
use App\Models\Concerns\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use Searchable, SoftDeletes;

    protected $fillable = [
        'title', 'course_category_id', 'hours', 'mentors', 'mode', 'level', 'scope', 'status',
        'description', 'outcomes', 'requirements', 'thumbnail',
    ];

    protected function casts(): array
    {
        return [
            'mentors' => 'array',
            'mode' => CourseMode::class,
            'level' => CourseLevel::class,
            'scope' => CourseScope::class,
            'status' => CourseStatus::class,
        ];
    }

    public static function searchableColumns(): array
    {
        return ['title', 'description'];
    }

    /** Display code such as CRS-007; derived from the id so it never needs its own column. */
    public function code(): string
    {
        return 'CRS-'.str_pad((string) $this->id, 3, '0', STR_PAD_LEFT);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CourseCategory::class, 'course_category_id');
    }

    /** Batches this course runs for; their students are the course's enrolled students. */
    public function batches(): BelongsToMany
    {
        return $this->belongsToMany(Batch::class, 'batch_course')->withTimestamps();
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(CourseChapter::class)->orderBy('position')->orderBy('id');
    }
}
