<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\StoreCourseCategoryRequest;
use App\Http\Resources\CourseCategoryResource;
use App\Models\CourseCategory;
use App\Services\CourseCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Course categories; writes are gated by the web route group (super_admin, admin, ob). */
class CourseCategoryController extends ApiController
{
    public function __construct(private readonly CourseCategoryService $categories) {}

    public function index(Request $request): JsonResponse
    {
        $q = $request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? null;

        return $this->ok(['categories' => CourseCategoryResource::collection($this->categories->list($q))]);
    }

    public function store(StoreCourseCategoryRequest $request): JsonResponse
    {
        $category = $this->categories->create($request->user(), $request->validated());

        return $this->ok(['category' => new CourseCategoryResource($category)], 201);
    }

    public function update(StoreCourseCategoryRequest $request, CourseCategory $category): JsonResponse
    {
        $category = $this->categories->update($request->user(), $category, $request->validated());

        return $this->ok(['category' => new CourseCategoryResource($category)]);
    }

    public function delete(Request $request, CourseCategory $category): JsonResponse
    {
        $this->categories->delete($request->user(), $category);

        return $this->ok(['message' => "{$category->name} has been deleted."]);
    }
}
