<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\StoreQuestionGroupRequest;
use App\Http\Resources\QuestionGroupResource;
use App\Models\QuestionGroup;
use App\Services\QuestionBankService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Question groups; access is gated by the web route groups. */
class QuestionGroupController extends ApiController
{
    public function __construct(private readonly QuestionBankService $bank) {}

    public function index(): JsonResponse
    {
        return $this->ok(['groups' => QuestionGroupResource::collection($this->bank->groups())]);
    }

    public function store(StoreQuestionGroupRequest $request): JsonResponse
    {
        $group = $this->bank->saveGroup($request->user(), $request->validated());

        return $this->ok(['group' => new QuestionGroupResource($group)], 201);
    }

    public function update(StoreQuestionGroupRequest $request, QuestionGroup $group): JsonResponse
    {
        $group = $this->bank->saveGroup($request->user(), $request->validated(), $group);

        return $this->ok(['group' => new QuestionGroupResource($group)]);
    }

    public function delete(Request $request, QuestionGroup $group): JsonResponse
    {
        $this->bank->deleteGroup($request->user(), $group);

        return $this->ok(['message' => "{$group->title} has been deleted."]);
    }
}
