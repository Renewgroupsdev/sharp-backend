<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One attendance row with its student, for the staff list (the student's own view uses AttendanceResource). @mixin \App\Models\Attendance */
class AttendanceListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student->public_id,
            'name' => $this->student->name,
            'org' => $this->student->org,
            'batch' => $this->student->batch?->name,
            'date' => $this->att_date->toDateString(),
            'status' => $this->status,
            'reason' => $this->reason,
            'marked_by_name' => $this->marker?->name,
            'on_site' => $this->on_site,
            'distance_m' => $this->distance_m,
            'created_at' => $this->created_at,
        ];
    }
}
