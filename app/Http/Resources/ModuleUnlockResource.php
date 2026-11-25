<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ModuleUnlock */
class ModuleUnlockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student->public_id,
            'name' => $this->student->name,
            'course_id' => $this->chapter->course_id,
            'course' => $this->chapter->course->title,
            'chapter_id' => $this->course_chapter_id,
            'module' => $this->chapter->name,
            'reason' => $this->reason,
            'unlocked_by' => $this->unlocker?->name,
            'created_at' => $this->created_at,
        ];
    }
}
