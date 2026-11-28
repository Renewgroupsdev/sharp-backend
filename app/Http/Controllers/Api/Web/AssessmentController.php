<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\StoreAssessmentRequest;
use App\Http\Requests\UpdateAssessmentSetupRequest;
use App\Http\Requests\UploadAssessmentAttachmentRequest;
use App\Http\Resources\AssessmentResource;
use App\Enums\Role;
use App\Models\Assessment;
use App\Support\StudentScope;
use App\Services\AssessmentAttachmentService;
use App\Services\AssessmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Quizzes, assignments and assessments; writes are gated by the web route group. */
class AssessmentController extends ApiController
{
    public function __construct(private readonly AssessmentService $assessments, private readonly AssessmentAttachmentService $files) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['nullable', 'string'],
            'course' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', 'string'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->ok(['assessments' => AssessmentResource::collection($this->assessments->list($filters, $request->user()))]);
    }

    /** A mentor works only on the courses they teach (an assessment names its course by title). */
    private function mentorOwns(Request $request, string $courseTitle): void
    {
        $user = $request->user();
        abort_if($user->role === Role::Mentor && ! StudentScope::courseIds($user)->where('c.title', $courseTitle)->exists(), 403, 'You can only work on the courses you teach.');
    }

    public function store(StoreAssessmentRequest $request): JsonResponse
    {
        $this->mentorOwns($request, (string) $request->validated('course'));
        $assessment = $this->assessments->save($request->user(), $request->validated());

        return $this->ok(['assessment' => new AssessmentResource($assessment)], 201);
    }

    public function update(StoreAssessmentRequest $request, Assessment $assessment): JsonResponse
    {
        $this->mentorOwns($request, (string) $assessment->course);
        $this->mentorOwns($request, (string) $request->validated('course'));
        $assessment = $this->assessments->save($request->user(), $request->validated(), $assessment);

        return $this->ok(['assessment' => new AssessmentResource($assessment)]);
    }

    /** The assignment brief: opens inline by default, `?download=1` forces a download. */
    public function attachment(Request $request, Assessment $assessment): StreamedResponse
    {
        return $this->files->respond($request->user(), $assessment, $request->boolean('download'));
    }

    public function uploadAttachment(UploadAssessmentAttachmentRequest $request, Assessment $assessment): JsonResponse
    {
        $this->mentorOwns($request, (string) $assessment->course);
        $assessment = $this->files->store($request->user(), $assessment, $request->file('file'));

        return $this->ok(['assessment' => new AssessmentResource($assessment->load('groups:id'))]);
    }

    public function deleteAttachment(Request $request, Assessment $assessment): JsonResponse
    {
        $this->mentorOwns($request, (string) $assessment->course);
        $assessment = $this->files->remove($request->user(), $assessment);

        return $this->ok(['assessment' => new AssessmentResource($assessment->load('groups:id'))]);
    }

    public function setup(UpdateAssessmentSetupRequest $request, Assessment $assessment): JsonResponse
    {
        $this->mentorOwns($request, (string) $assessment->course);
        $assessment = $this->assessments->saveSetup($request->user(), $assessment, $request->validated());

        return $this->ok(['assessment' => new AssessmentResource($assessment)]);
    }

    public function renameCourse(Request $request): JsonResponse
    {
        abort_if($request->user()->role === Role::Mentor, 403, 'Only staff can rename a course.');
        $data = $request->validate(['from' => ['required', 'string', 'max:150'], 'to' => ['required', 'string', 'max:150']]);

        return $this->ok(['updated' => $this->assessments->renameCourse($request->user(), $data['from'], $data['to'])]);
    }

    public function delete(Request $request, Assessment $assessment): JsonResponse
    {
        abort_if($request->user()->role === Role::Mentor, 403, 'Only staff can delete an assessment.');
        $this->assessments->delete($request->user(), $assessment);

        return $this->ok(['message' => "{$assessment->title} has been deleted."]);
    }
}
