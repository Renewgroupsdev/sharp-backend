<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\StoreBatchRequest;
use App\Http\Resources\BatchResource;
use App\Models\Batch;
use App\Services\BatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Programme batches; writes are gated by the web route group (super_admin, admin, ob). */
class BatchController extends ApiController
{
    public function __construct(private readonly BatchService $batches) {}

    public function index(Request $request): JsonResponse
    {
        return $this->ok(['batches' => BatchResource::collection($this->batches->list($request->user()))]);
    }

    public function store(StoreBatchRequest $request): JsonResponse
    {
        $batch = $this->batches->create($request->user(), $request->validated());

        return $this->ok(['batch' => new BatchResource($batch)], 201);
    }

    public function update(StoreBatchRequest $request, Batch $batch): JsonResponse
    {
        $batch = $this->batches->update($request->user(), $batch, $request->validated());

        return $this->ok(['batch' => new BatchResource($batch)]);
    }

    public function delete(Request $request, Batch $batch): JsonResponse
    {
        $this->batches->delete($request->user(), $batch);

        return $this->ok(['message' => "{$batch->name} has been deleted."]);
    }
}
