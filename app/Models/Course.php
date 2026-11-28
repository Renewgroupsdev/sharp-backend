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
use Illuminate\Support\Facades\DB;

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

    /** Students enrolled in this course one by one (as well as through a batch). */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'student_course')->withTimestamps();
    }

    /** Courses a student takes: those of their batch plus any given to them directly. */
    public function scopeForStudent($query, User $student)
    {
        return $query->where(fn ($w) => $w
            ->whereIn('courses.id', DB::table('batch_course')->where('batch_id', $student->batch_id ?? 0)->select('course_id'))
            ->orWhereIn('courses.id', DB::table('student_course')->where('user_id', $student->id)->select('course_id')));
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(CourseChapter::class)->orderBy('position')->orderBy('id');
    }
}
