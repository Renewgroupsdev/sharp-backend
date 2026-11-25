<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\AttendanceRequest */
class AttendanceRequestResource extends JsonResource
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
            'requested_status' => $this->requested_status,
            'reason' => $this->reason,
            'status' => $this->status,
            'decision' => $this->decision,
            'resolved_by' => $this->resolver?->name,
            'resolved_at' => $this->resolved_at,
            'resolution_note' => $this->resolution_note,
            'requested_at' => $this->updated_at,
        ];
    }
}
