<?php

namespace App\Http\Resources;

use App\Enums\LearningStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** Wraps a row of LearningService (a plain object, not a model). */
class LearningResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'student_id' => $this->student_id,
            'name' => $this->name,
            'org' => $this->org,
            'course_id' => $this->course_id,
            'course' => $this->course,
            'progress' => (int) $this->progress,
            'materials' => (int) $this->materials,
            'last_accessed' => $this->last_accessed_at ? Carbon::parse($this->last_accessed_at)->toDateString() : null,
            'status' => LearningStatus::fromProgress((int) $this->progress),
        ];
    }
}
