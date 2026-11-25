<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Attendance */
class AttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'att_date' => $this->att_date->toDateString(),
            'status' => $this->status,
            'reason' => $this->reason,
            'marked_by' => $this->marked_by,
            'marked_by_name' => $this->marker?->name,
            'created_at' => $this->created_at,
        ];
    }
}
