<?php

namespace App\Http\Controllers\Api\Web;

use App\Enums\AssessmentType;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\GradeQuizAnswerRequest;
use App\Http\Resources\QuizAttemptResource;
use App\Models\QuizAttempt;
use App\Services\QuizAttemptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Quiz attempts for staff and mentors. Role access is set by the route group; the batch limit by StudentScope. */
class QuizAttemptController extends ApiController
{
    public function __construct(private readonly QuizAttemptService $attempts) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'assessment_id' => ['nullable', 'integer'],
            'student_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::in([AssessmentType::Quiz->value, AssessmentType::Assessment->value])],
            'outcome' => ['nullable', Rule::in(QuizAttempt::OUTCOMES)],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $this->attempts->paginate($request->user(), $filters);

        return $this->ok([
            'attempts' => QuizAttemptResource::collection($page->getCollection()),
            'counts' => $this->attempts->counts($request->user(), $filters),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    /** Marks one long answer by hand and returns the attempt in the same shape as `show`. */
    public function grade(GradeQuizAnswerRequest $request, QuizAttempt $attempt): JsonResponse
    {
        $data = $request->validated();
        $attempt = $this->attempts->gradeQuestion($request->user(), $attempt, (int) $data['question_id'], (int) $data['marks'], $data['feedback'] ?? null);
        $attempt->load('assessment:id,type,title,course,min_percent', 'student:id,public_id,name,batch_id');

        return $this->ok([
            'attempt' => new QuizAttemptResource($attempt),
            'questions' => $this->attempts->questions($attempt),
        ]);
    }

    public function show(Request $request, QuizAttempt $attempt): JsonResponse
    {
        $this->attempts->assertCanView($request->user(), $attempt);
        $attempt->load('assessment:id,type,title,course,min_percent');

        return $this->ok([
            'attempt' => new QuizAttemptResource($attempt),
            'questions' => $this->attempts->questions($attempt),
        ]);
    }
}
