<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Assessment */
class AssessmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'course' => $this->course,
            'module' => $this->module,
            'topic' => $this->topic,
            'status' => $this->status,
            'due_date' => $this->due_date?->toDateString(),
            'max_marks' => $this->max_marks,
            'min_percent' => $this->min_percent,
            'pass_marks' => (int) round($this->max_marks * $this->min_percent / 100),
            'attempts' => $this->attempts,
            'description' => $this->description,
            'attachment' => $this->attachmentSummary(),
            'setup' => $this->setup,
            'group_ids' => $this->whenLoaded('groups', fn () => $this->groups->pluck('id')->values()),
        ];
    }
}
