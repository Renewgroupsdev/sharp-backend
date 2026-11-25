<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\StoreQuizResultRequest;
use App\Http\Resources\QuizResultResource;
use App\Services\AssessmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Quiz report data; access is gated by the web route groups. */
class QuizResultController extends ApiController
{
    public function __construct(private readonly AssessmentService $assessments) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'assessment_id' => ['nullable', 'integer'],
            'course' => ['nullable', 'string', 'max:150'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->ok(['results' => QuizResultResource::collection($this->assessments->results($filters))]);
    }

    public function store(StoreQuizResultRequest $request): JsonResponse
    {
        $result = $this->assessments->recordResult($request->user(), $request->validated());

        return $this->ok(['result' => new QuizResultResource($result)], 201);
    }
}
