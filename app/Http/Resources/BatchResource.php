<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Batch */
class BatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'seats' => $this->seats,
            'mentors' => $this->mentors,
            'status' => $this->status,
            'student_count' => $this->whenCounted('students'),
            'colleges' => $this->whenLoaded('colleges', fn () => $this->colleges->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values()),
            'courses' => $this->whenLoaded('courses', fn () => $this->courses->map(fn ($c) => ['id' => $c->id, 'code' => $c->code(), 'title' => $c->title])->values()),
            'created_at' => $this->created_at,
        ];
    }
}
