<?php

namespace App\Services;

use App\Enums\AssessmentStatus;
use App\Enums\AssessmentType;
use App\Enums\Role;
use App\Enums\SubmissionStatus;
use App\Models\Assessment;
use App\Models\AssessmentSubmission;
use App\Models\SubmissionAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AssignmentSubmissionService
{
    public function __construct(private readonly AuditLogger $audit, private readonly NotificationService $notifications) {}

    public function submit(User $student, Assessment $assessment, array $data): AssessmentSubmission
    {
        abort_unless($student->role === Role::Student, 403);
        abort_unless(($assessment->type?->value ?? $assessment->type) === AssessmentType::Assignment->value, 422, 'Only assignments accept submissions.');
        abort_unless($assessment->status === AssessmentStatus::Published, 404, 'Assessment not found.');
        $this->assertStudentEligible($student, $assessment);

        if ($assessment->due_date && now()->gt($assessment->due_date->copy()->endOfDay())) {
            throw ValidationException::withMessages(['submission' => 'The submission deadline has passed.']);
        }

        $content = trim((string) ($data['content'] ?? ''));
        /** @var array<int, UploadedFile> $files */
        $files = $data['files'] ?? [];
        if ($content === '' && count($files) === 0) {
            throw ValidationException::withMessages(['content' => 'Provide written content or at least one attachment.']);
        }

        $storedPaths = [];
        try {
            $submission = DB::transaction(function () use ($student, $assessment, $content, $files, &$storedPaths) {
                // Serialize concurrent submissions for the same learner and assessment.
                $lockedAssessment = Assessment::query()->whereKey($assessment->id)->lockForUpdate()->firstOrFail();
                abort_unless($lockedAssessment->status === AssessmentStatus::Published, 404, 'Assessment not found.');
                if ($lockedAssessment->due_date && now()->gt($lockedAssessment->due_date->copy()->endOfDay())) {
                    throw ValidationException::withMessages(['submission' => 'The submission deadline has passed.']);
                }
                $latest = AssessmentSubmission::query()
                    ->where('assessment_id', $assessment->id)->where('student_id', $student->id)
                    ->orderByDesc('submission_no')->lockForUpdate()->first();

                if ($latest && $latest->status === SubmissionStatus::UnderReview) {
                    throw ValidationException::withMessages(['submission' => 'Your submission is currently under review.']);
                }
                $number = (int) ($latest?->submission_no ?? 0) + 1;
                $submission = AssessmentSubmission::create([
                    'assessment_id' => $assessment->id,
                    'student_id' => $student->id,
                    'submission_no' => $number,
                    'content' => $content !== '' ? $content : null,
                    'status' => SubmissionStatus::Submitted,
                    'submitted_at' => now(),
                ]);

                $disk = config('filesystems.assessment_disk', 'local');
                foreach ($files as $file) {
                    $path = $file->store("assessment-submissions/{$assessment->id}/{$student->id}", $disk);
                    if (! $path) {
                        throw new \RuntimeException('Unable to store an uploaded attachment.');
                    }
                    $storedPaths[] = [$disk, $path];
                    $submission->attachments()->create([
                        'original_name' => $file->getClientOriginalName(),
                        'storage_path' => $path,
                        'disk' => $disk,
                        'mime_type' => $file->getMimeType(),
                        'file_size' => $file->getSize(),
                    ]);
                }

                return $submission->load(['attachments', 'student:id,public_id,name,batch_id', 'assessment:id,type,title,course,due_date,max_marks']);
            });
        } catch (\Throwable $e) {
            foreach ($storedPaths as [$disk, $path]) Storage::disk($disk)->delete($path);
            throw $e;
        }

        $this->audit->record($student, 'Submitted assignment', (string) $submission->id, $assessment->title.' · version '.$submission->submission_no);
        $this->notifications->notifyStaff('assignment_submitted', $student->batch_id, [
            'student' => $student->name, 'batch' => $student->batch?->name ?? 'no batch', 'assessment' => $assessment->title, 'version' => $submission->submission_no,
        ], ['url' => '/app/submissions']);
        return $submission;
    }

    /** Admin correction of a student's written answer. */
    public function updateContent(User $actor, AssessmentSubmission $submission, string $content): AssessmentSubmission
    {
        $submission->update(['content' => $content]);
        $submission->loadMissing('assessment');
        $this->audit->record($actor, 'Edited student submission', (string) $submission->id, ($submission->assessment?->title ?? 'Assignment').' · version '.$submission->submission_no);

        return $submission->fresh(['assessment:id,title,course,max_marks', 'student:id,public_id,name,batch_id', 'attachments', 'grader:id,name']);
    }

    /** Admin removal of a submission at any status; its files are deleted from storage too. */
    public function remove(User $actor, AssessmentSubmission $submission): void
    {
        $submission->loadMissing('assessment', 'attachments');
        $files = $submission->attachments->map(fn (SubmissionAttachment $a) => [$a->disk, $a->storage_path])->all();
        $label = ($submission->assessment?->title ?? 'Assignment').' · version '.$submission->submission_no;
        $id = (string) $submission->id;
        $submission->delete(); // attachment rows go with it (cascade)

        foreach ($files as [$disk, $path]) {
            Storage::disk($disk)->delete($path);
        }
        $this->audit->record($actor, 'Deleted student submission', $id, $label);
    }

    public function submissions(User $actor, array $filters = [])
    {
        return AssessmentSubmission::query()
            ->with(['assessment:id,type,title,course,due_date,max_marks', 'student:id,public_id,name,batch_id', 'attachments', 'grader:id,name'])
            ->whereHas('assessment', fn ($q) => $q->where('type', AssessmentType::Assignment->value))
            ->when($actor->role === Role::Mentor, fn ($q) => $q->whereHas('student', fn ($s) => $s->where('batch_id', $actor->batch_id ?? 0)))
            ->when($filters['assessment_id'] ?? null, fn ($q, $id) => $q->where('assessment_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->whereHas('student', fn ($s) => $s->where('name', 'ilike', "%{$term}%")->orWhere('public_id', 'ilike', "%{$term}%")))
            ->orderByDesc('submitted_at')->paginate(min((int) ($filters['per_page'] ?? 20), 100));
    }

    public function markUnderReview(User $actor, AssessmentSubmission $submission): AssessmentSubmission
    {
        $submission->loadMissing(['student', 'assessment']);
        abort_if($actor->role === Role::Mentor && $submission->student->batch_id !== $actor->batch_id, 403, 'You can only review students in your own batch.');
        abort_if($submission->status === SubmissionStatus::Graded, 422, 'A graded submission cannot be moved back to review.');
        $submission->update(['status' => SubmissionStatus::UnderReview]);
        $this->audit->record($actor, 'Started assignment review', (string) $submission->id, $submission->assessment?->title);
        $this->notifications->notify('submission_review_started', $submission->student_id, ['assessment' => $submission->assessment?->title, 'reviewer' => $actor->name], ['url' => '/app/assessments']);
        return $submission->fresh(['assessment:id,title,course,max_marks', 'student:id,public_id,name,batch_id', 'attachments', 'grader:id,name']);
    }

    public function grade(User $actor, AssessmentSubmission $submission, array $data): AssessmentSubmission
    {
        $submission->loadMissing(['assessment', 'student']);
        abort_if($actor->role === Role::Mentor && $submission->student->batch_id !== $actor->batch_id, 403, 'You can only evaluate students in your own batch.');
        abort_unless(($submission->assessment->type?->value ?? $submission->assessment->type) === AssessmentType::Assignment->value, 422);
        abort_if($submission->status === SubmissionStatus::Graded, 422, 'This submission has already been graded.');

        $marks = (float) $data['marks_obtained'];
        if ($marks > (float) $submission->assessment->max_marks) {
            throw ValidationException::withMessages(['marks_obtained' => 'Marks cannot exceed the maximum marks for this assessment.']);
        }

        $submission->update([
            'marks_obtained' => $marks,
            'feedback' => $data['feedback'] ?? null,
            'status' => $data['status'] ?? SubmissionStatus::Graded->value,
            'graded_by' => $actor->id,
            'graded_at' => now(),
        ]);

        $returned = ($data['status'] ?? SubmissionStatus::Graded->value) === SubmissionStatus::Returned->value;
        $this->notifications->notify('assignment_graded', $submission->student_id, [
            'assessment' => $submission->assessment->title, 'outcome' => $returned ? 'returned' : 'graded', 'marks' => $marks,
            'max' => $submission->assessment->max_marks, 'feedback' => ! empty($data['feedback']) ? ' Feedback: '.$data['feedback'] : '',
        ], ['url' => '/app/assessments']);
        $this->audit->record($actor, $returned ? 'Returned assignment' : 'Graded assignment', (string) $submission->id, $submission->assessment->title.' · '.$marks.'/'.$submission->assessment->max_marks);
        return $submission->fresh(['assessment:id,title,course,max_marks', 'student:id,public_id,name,batch_id', 'attachments', 'grader:id,name']);
    }

    public function attachmentFor(User $actor, SubmissionAttachment $attachment): SubmissionAttachment
    {
        $attachment->loadMissing('submission.student');
        $submission = $attachment->submission;
        if ($actor->role === Role::Student) {
            abort_unless($submission->student_id === $actor->id, 404);
        } elseif ($actor->role === Role::Mentor) {
            abort_unless($submission->student->batch_id === $actor->batch_id, 403);
        }
        return $attachment;
    }

    private function assertStudentEligible(User $student, Assessment $assessment): void
    {
        $eligible = \App\Models\Course::query()
            ->where('title', $assessment->course)
            ->where('status', \App\Enums\CourseStatus::Published->value)
            ->whereHas('batches', fn ($q) => $q->where('batches.id', $student->batch_id ?? 0))
            ->exists();
        abort_unless($eligible, 404, 'Assessment not found.');
    }
}
