<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\QuestionGroup */
class QuestionGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'type' => $this->type,
            'question_count' => $this->whenCounted('questions'),
            'quiz_count' => $this->whenCounted('quizzes'),
        ];
    }
}
