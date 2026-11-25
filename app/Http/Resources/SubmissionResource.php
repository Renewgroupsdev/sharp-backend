<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SubmissionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'assessment_id' => $this->assessment_id,
            'assessment' => $this->whenLoaded('assessment', fn () => [
                'id' => $this->assessment->id,
                'type' => $this->assessment->type?->value ?? $this->assessment->type,
                'title' => $this->assessment->title,
                'course' => $this->assessment->course,
                'due_date' => $this->assessment->due_date?->toDateString(),
                'max_marks' => $this->assessment->max_marks,
            ]),
            'student' => $this->whenLoaded('student', fn () => [
                'id' => $this->student->id,
                'public_id' => $this->student->public_id,
                'name' => $this->student->name,
                'batch_id' => $this->student->batch_id,
            ]),
            'submission_no' => $this->submission_no,
            'content' => $this->content,
            'status' => $this->status?->value ?? $this->status,
            'marks_obtained' => $this->marks_obtained,
            'feedback' => $this->feedback,
            'graded_by' => $this->graded_by,
            'grader' => $this->whenLoaded('grader', fn () => ['id' => $this->grader->id, 'name' => $this->grader->name]),
            'submitted_at' => $this->submitted_at?->toISOString(),
            'graded_at' => $this->graded_at?->toISOString(),
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($file) => [
                'id' => $file->id,
                'name' => $file->original_name,
                'mime_type' => $file->mime_type,
                'size' => $file->file_size,
                'download_url' => route('web.submissions.attachments.download', ['attachment' => $file->id]),
            ])->values()),
        ];
    }
}
