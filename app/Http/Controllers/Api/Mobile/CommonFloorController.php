<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\ApiController;

use App\Enums\ModerationAction;
use App\Http\Requests\PostMessageRequest;
use App\Http\Resources\CommonFloorMessageResource;
use App\Models\CommonFloorMessage;
use App\Services\CommonFloorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommonFloorController extends ApiController
{
    public function __construct(protected readonly CommonFloorService $floor) {}

    public function index(): JsonResponse
    {
        return $this->ok(['messages' => CommonFloorMessageResource::collection($this->floor->recent())]);
    }

    public function store(PostMessageRequest $request): JsonResponse
    {
        $message = $this->floor->post($request->user(), $request->validated('message'));

        return $this->ok(['message_row' => new CommonFloorMessageResource($message)], 201);
    }

    public function moderate(Request $request, CommonFloorMessage $message, ModerationAction $action): JsonResponse
    {
        $this->floor->moderate($request->user(), $message, $action);

        return $this->ok(['message' => 'Done.']);
    }
}
