<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\SubmitQuizRequest;
use App\Http\Requests\StoreAssignmentSubmissionRequest;
use App\Http\Resources\SubmissionResource;
use App\Models\Assessment;
use App\Services\AssignmentSubmissionService;
use App\Services\MyAssessmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A student's own quizzes, assignments and assessments; the route group limits it to the student role. */
class MyAssessmentController extends ApiController
{
    public function __construct(private readonly MyAssessmentService $assessments, private readonly AssignmentSubmissionService $submissions) {}

    public function index(Request $request): JsonResponse
    {
        return $this->ok(['assessments' => $this->assessments->list($request->user())]);
    }

    public function show(Request $request, int $assessment): JsonResponse
    {
        return $this->ok($this->assessments->detail($request->user(), $assessment));
    }

    /** Starts an attempt, or resumes the one in progress, and returns its questions (never their answers). */
    public function start(Request $request, int $assessment): JsonResponse
    {
        return $this->ok($this->assessments->start($request->user(), $assessment));
    }

    public function submit(SubmitQuizRequest $request, int $assessment): JsonResponse
    {
        $data = $request->validated();

        return $this->ok(['result' => $this->assessments->submit($request->user(), $assessment, (int) $data['attempt_id'], $data['answers'])]);
    }


    public function submitAssignment(StoreAssignmentSubmissionRequest $request, int $assessment): JsonResponse
    {
        $model = Assessment::query()->findOrFail($assessment);
        $submission = $this->submissions->submit($request->user(), $model, $request->validated());
        return $this->ok(['submission' => new SubmissionResource($submission)], 201);
    }

    public function deleteSubmission(Request $request, int $assessment, int $submission): JsonResponse
    {
        $model = \App\Models\AssessmentSubmission::query()->where('assessment_id', $assessment)->findOrFail($submission);
        $this->submissions->removeOwn($request->user(), $model);

        return $this->ok(['message' => 'Submission deleted.']);
    }

    public function assignmentSubmissions(Request $request, int $assessment): JsonResponse
    {
        $model = Assessment::query()->findOrFail($assessment);
        abort_unless(($model->type?->value ?? $model->type) === 'assignment', 422);
        $this->assessments->detail($request->user(), $assessment); // enforces published course/batch visibility
        $items = \App\Models\AssessmentSubmission::query()
            ->where('assessment_id', $model->id)->where('student_id', $request->user()->id)
            ->with(['attachments', 'assessment:id,type,title,course,due_date,max_marks'])->orderByDesc('submission_no')->get();
        return $this->ok(['submissions' => SubmissionResource::collection($items)]);
    }

    public function review(Request $request, int $assessment): JsonResponse
    {
        $attemptId = $request->validate(['attempt_id' => ['nullable', 'integer']])['attempt_id'] ?? null;

        return $this->ok($this->assessments->review($request->user(), $assessment, $attemptId));
    }
}
