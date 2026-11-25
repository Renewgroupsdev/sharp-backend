<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\QuizResult */
class QuizResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'assessment_id' => $this->assessment_id,
            'quiz' => $this->assessment->title,
            'course' => $this->assessment->course,
            'min_percent' => $this->assessment->min_percent,
            'student_id' => $this->student_id,
            'student_public_id' => $this->student->public_id,
            'student_name' => $this->student->name,
            'marks' => $this->marks,
            'total' => $this->total,
            'result_percent' => $this->result_percent,
            'attempts' => $this->attempts,
            'passed' => $this->result_percent >= $this->assessment->min_percent,
        ];
    }
}
