<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\ApiController;

use App\Http\Requests\StoreCollegeRequest;
use App\Http\Resources\CollegeResource;
use App\Services\CollegeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CollegeController extends ApiController
{
    public function __construct(protected readonly CollegeService $colleges) {}

    public function index(): JsonResponse
    {
        return $this->ok(['colleges' => CollegeResource::collection($this->colleges->list())]);
    }

    public function suggestCode(Request $request): JsonResponse
    {
        $name = $request->validate(['name' => ['required', 'string']])['name'];

        return $this->ok(['code' => $this->colleges->suggestCode($name)]);
    }

    public function store(StoreCollegeRequest $request): JsonResponse
    {
        $college = $this->colleges->create($request->user(), $request->validated());

        return $this->ok(['college' => new CollegeResource($college)], 201);
    }
}
