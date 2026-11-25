<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** One student's row on the enrollment detail page (a plain object from EnrollmentService::detail). */
class EnrollmentStudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'student_id' => $this->student_id,
            'name' => $this->name,
            'org' => $this->org,
            'status' => $this->status,
            'progress' => (int) $this->progress,
            'lessons_done' => (int) $this->lessons_done,
            'last_accessed' => $this->last_accessed_at ? Carbon::parse($this->last_accessed_at)->toDateString() : null,
            'attendance_rate' => $this->attendance_rate === null ? null : (int) $this->attendance_rate,
            'quiz_avg' => $this->quiz_avg === null ? null : (int) $this->quiz_avg,
            'certificate' => $this->certificate_id ? [
                'id' => (int) $this->certificate_id,
                'status' => $this->certificate_status,
                'number' => $this->certificate_number,
            ] : null,
        ];
    }
}
