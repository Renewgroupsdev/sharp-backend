<?php

namespace App\Http\Controllers\Api\Web;

use App\Enums\LearningStatus;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\SaveLearningProgressRequest;
use App\Http\Resources\LearningResource;
use App\Services\LearningService;
use App\Services\LessonProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Learning Management: every enrolled student's progress per course. Reads are staff + mentors; writes are gated by the route group. */
class LearningController extends ApiController
{
    public function __construct(private readonly LearningService $learning, private readonly LessonProgressService $lessons) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'course_id' => ['nullable', 'integer'],
            'org' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', Rule::enum(LearningStatus::class)],
        ]);

        return $this->ok(['records' => LearningResource::collection($this->learning->list($filters))]);
    }

    /** Per-module breakdown from the student's real lesson completions. */
    public function report(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'string'],
            'course_id' => ['required', 'integer'],
        ]);

        return $this->ok(['modules' => $this->lessons->report($data['student_id'], (int) $data['course_id'])]);
    }

    /** Add or edit: one stored row per (student, course). */
    public function save(SaveLearningProgressRequest $request): JsonResponse
    {
        return $this->ok(['record' => new LearningResource($this->learning->save($request->user(), $request->validated()))]);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'string'],
            'course_id' => ['required', 'integer'],
        ]);

        return $this->ok(['record' => new LearningResource($this->learning->reset($request->user(), $data['student_id'], (int) $data['course_id']))]);
    }
}
