<?php

namespace App\Models;

use App\Enums\LessonHost;
use App\Enums\LessonPrivacy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseLesson extends Model
{
    protected $fillable = [
        'course_chapter_id', 'name', 'duration_minutes', 'host', 'url', 'content', 'privacy', 'description',
        'file_name', 'file_size', 'position',
    ];

    protected function casts(): array
    {
        return ['host' => LessonHost::class, 'privacy' => LessonPrivacy::class];
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(CourseChapter::class, 'course_chapter_id');
    }
}
