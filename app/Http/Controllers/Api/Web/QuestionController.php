<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\StoreQuestionRequest;
use App\Http\Resources\QuestionResource;
use App\Models\Question;
use App\Services\QuestionBankService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The question bank; access is gated by the web route groups. */
class QuestionController extends ApiController
{
    public function __construct(private readonly QuestionBankService $bank) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'group_id' => ['nullable', 'integer'],
            'type' => ['nullable', 'string'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->ok(['questions' => QuestionResource::collection($this->bank->questions($filters))]);
    }

    public function store(StoreQuestionRequest $request): JsonResponse
    {
        $question = $this->bank->saveQuestion($request->user(), $request->validated());

        return $this->ok(['question' => new QuestionResource($question)], 201);
    }

    public function update(StoreQuestionRequest $request, Question $question): JsonResponse
    {
        $question = $this->bank->saveQuestion($request->user(), $request->validated(), $question);

        return $this->ok(['question' => new QuestionResource($question)]);
    }

    public function delete(Request $request, Question $question): JsonResponse
    {
        $this->bank->deleteQuestion($request->user(), $question);

        return $this->ok(['message' => 'Question deleted.']);
    }
}
