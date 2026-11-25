<?php

namespace App\Http\Resources;

use App\Enums\EnrollmentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** Wraps a row of EnrollmentService (a plain object, not a model). */
class EnrollmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $date = fn ($value) => $value ? Carbon::parse($value)->toDateString() : null;

        return [
            'id' => (int) $this->id,
            'course_id' => (int) $this->course_id,
            'course_code' => 'CRS-'.str_pad((string) $this->course_id, 3, '0', STR_PAD_LEFT),
            'course' => $this->course,
            'batch_id' => (int) $this->batch_id,
            'batch' => $this->batch,
            'start_date' => $date($this->start_date),
            'end_date' => $date($this->end_date),
            'students_count' => (int) $this->students_count,
            'mentors_count' => (int) $this->mentors_count,
            'status' => EnrollmentStatus::of($this->start_date, $this->end_date, $this->batch_status),
        ];
    }
}
