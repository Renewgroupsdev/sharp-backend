<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Wraps a row of EnrollmentService::history (a plain object, not a model). */
class EnrollmentEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'course_id' => (int) $this->course_id,
            'course' => $this->course,
            'batch_id' => (int) $this->batch_id,
            'batch' => $this->batch,
            'event' => $this->event,
            'source' => $this->source,
            'note' => $this->note,
            'actor' => $this->actor,
            'created_at' => $this->created_at,
        ];
    }
}
