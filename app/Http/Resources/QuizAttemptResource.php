<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\QuizAttempt */
class QuizAttemptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'attempt_no' => $this->attempt_no,
            'outcome' => $this->outcome(),
            'assessment' => $this->whenLoaded('assessment', fn () => [
                'id' => $this->assessment->id,
                'type' => $this->assessment->type?->value ?? $this->assessment->type,
                'title' => $this->assessment->title,
                'course' => $this->assessment->course,
                'min_percent' => $this->assessment->min_percent,
            ]),
            'student' => $this->whenLoaded('student', fn () => [
                'id' => $this->student->id,
                'public_id' => $this->student->public_id,
                'name' => $this->student->name,
                'batch_id' => $this->student->batch_id,
            ]),
            'started_at' => $this->started_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'submitted_at' => $this->submitted_at?->toISOString(),
            'time_taken_sec' => $this->time_taken_sec,
            'marks' => $this->marks,
            'total' => $this->total,
            'result_percent' => $this->result_percent,
            'passed' => $this->passed,
            'needs_manual_grading' => (bool) $this->needs_manual_grading,
            'question_count' => count($this->question_order ?? []),
        ];
    }
}
