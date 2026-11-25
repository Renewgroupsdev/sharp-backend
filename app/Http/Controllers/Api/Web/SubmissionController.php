<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\GradeAssignmentSubmissionRequest;
use App\Http\Resources\SubmissionResource;
use App\Models\AssessmentSubmission;
use App\Models\SubmissionAttachment;
use App\Services\AssignmentSubmissionService;
use App\Services\SubmissionStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubmissionController extends ApiController
{
    public function __construct(private readonly AssignmentSubmissionService $submissions, private readonly SubmissionStatsService $stats) {}

    public function stats(Request $request): JsonResponse
    {
        return $this->ok(['stats' => $this->stats->for($request->user())]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'assessment_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:submitted,under_review,graded,returned'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $this->submissions->submissions($request->user(), $filters);

        return $this->ok([
            'submissions' => SubmissionResource::collection($page->getCollection()),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, AssessmentSubmission $submission): JsonResponse
    {
        $this->assertCanView($request->user(), $submission);
        return $this->ok(['submission' => new SubmissionResource($submission->load(['assessment:id,type,title,course,due_date,max_marks', 'student:id,public_id,name,batch_id', 'attachments', 'grader:id,name']))]);
    }

    public function review(Request $request, AssessmentSubmission $submission): JsonResponse
    {
        return $this->ok(['submission' => new SubmissionResource($this->submissions->markUnderReview($request->user(), $submission))]);
    }

    public function grade(GradeAssignmentSubmissionRequest $request, AssessmentSubmission $submission): JsonResponse
    {
        $graded = $this->submissions->grade($request->user(), $submission, $request->validated());
        return $this->ok(['submission' => new SubmissionResource($graded)]);
    }

    /** Admin and super admin only (route middleware): corrects the text of a student's answer. */
    public function update(Request $request, AssessmentSubmission $submission): JsonResponse
    {
        $data = $request->validate(['content' => ['required', 'string', 'max:50000']]);

        return $this->ok(['submission' => new SubmissionResource($this->submissions->updateContent($request->user(), $submission, trim($data['content'])))]);
    }

    /** Admin and super admin only (route middleware): removes a submission and its files. */
    public function destroy(Request $request, AssessmentSubmission $submission): JsonResponse
    {
        $this->submissions->remove($request->user(), $submission);

        return $this->ok(['message' => 'Submission deleted.']);
    }

    public function download(Request $request, SubmissionAttachment $attachment): StreamedResponse
    {
        $file = $this->submissions->attachmentFor($request->user(), $attachment);
        abort_unless(Storage::disk($file->disk)->exists($file->storage_path), 404, 'Attachment not found.');
        return Storage::disk($file->disk)->download($file->storage_path, $file->original_name, [
            'Content-Type' => $file->mime_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function assertCanView($actor, AssessmentSubmission $submission): void
    {
        $submission->loadMissing('student');
        if ($actor->role->value === 'student') abort_unless($submission->student_id === $actor->id, 404);
        if ($actor->role->value === 'mentor') abort_unless($submission->student->batch_id === $actor->batch_id, 403);
    }
}
