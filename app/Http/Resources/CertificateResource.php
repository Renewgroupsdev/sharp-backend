<?php

namespace App\Http\Resources;

use App\Enums\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A certificate. An issued one carries the design and marks it was issued with; a pending one shows the current design and
 * the student's live attendance and score. Students do not see the mentor's note or the eligibility figures.
 *
 * @mixin \App\Models\Certificate
 */
class CertificateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $student = $request->user()?->role === Role::Student;
        $metrics = $this->getAttribute('metrics') ?? [];
        $issued = $this->issued_at !== null;

        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'student_id' => $this->student->public_id,
            'name' => $this->student->name,
            'org' => $this->student->org,
            'photo' => \App\Support\AvatarStore::url($this->student->avatar_url),
            'batch' => $this->student->batch?->name,
            'course_id' => $this->course_id,
            'course' => $this->course->title,
            'score' => $issued ? $this->score : ($metrics['score'] ?? null),
            'attendance' => $issued ? $this->attendance : ($metrics['attendance'] ?? null),
            'issued_at' => $this->issued_at,
            'issue_date' => $this->issue_date?->toDateString(),
            'cancelled_at' => $this->cancelled_at,
            'cancel_reason' => $this->cancel_reason,
            'cancel_note' => $this->cancel_note,
            'created_at' => $this->created_at,
            'design' => $this->design ?? $this->getAttribute('live_design'),
        ] + ($student ? [] : [
            'eligibility' => $metrics ? ['ok' => $metrics['ok'], 'min_attendance' => $metrics['min_attendance'], 'min_score' => $metrics['min_score']] : null,
            'override' => (bool) $this->eligibility_override,
            'score_live' => $this->getAttribute('score_live'),
            'edited' => $this->score_override !== null || $this->design_override !== null || $this->issue_date !== null || $this->min_attendance_override !== null,
            'mentor' => [
                'decision' => $this->mentor_decision,
                'note' => $this->mentor_note,
                'by' => $this->mentorReviewer?->name,
                'at' => $this->mentor_reviewed_at,
            ],
        ]);
    }
}
