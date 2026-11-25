<?php

namespace App\Http\Controllers\Api\Web;

use App\Enums\CourseStatus;
use App\Enums\Role;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\StoreCourseRequest;
use App\Http\Requests\SyncCourseChaptersRequest;
use App\Http\Resources\CourseResource;
use App\Models\Course;
use App\Services\CourseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Courses and their curriculum; writes are gated by the web route group (super_admin, admin, ob). */
class CourseController extends ApiController
{
    public function __construct(private readonly CourseService $courses) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(CourseStatus::class)],
            'category_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'trashed' => ['nullable', 'boolean'],
        ]);

        return $this->ok(['courses' => CourseResource::collection($this->courses->list($request->user(), $filters))]);
    }

    public function show(Request $request, Course $course): JsonResponse
    {
        abort_if(! $request->user()->hasRole(...Role::staff()) && $course->status !== CourseStatus::Published, 404);

        return $this->ok(['course' => new CourseResource($this->courses->load($course))]);
    }

    public function store(StoreCourseRequest $request): JsonResponse
    {
        $course = $this->courses->create($request->user(), $request->validated());

        return $this->ok(['course' => new CourseResource($course)], 201);
    }

    public function update(StoreCourseRequest $request, Course $course): JsonResponse
    {
        $course = $this->courses->update($request->user(), $course, $request->validated());

        return $this->ok(['course' => new CourseResource($course)]);
    }

    public function chapters(SyncCourseChaptersRequest $request, Course $course): JsonResponse
    {
        $course = $this->courses->replaceChapters($request->user(), $course, $request->validated('chapters'));

        return $this->ok(['course' => new CourseResource($course)]);
    }

    public function delete(Request $request, Course $course): JsonResponse
    {
        $this->courses->trash($request->user(), $course);

        return $this->ok(['message' => "{$course->title} was moved to Deleted."]);
    }

    public function restore(Request $request, Course $course): JsonResponse
    {
        return $this->ok(['course' => new CourseResource($this->courses->restore($request->user(), $course))]);
    }

    public function purge(Request $request, Course $course): JsonResponse
    {
        $this->courses->purge($request->user(), $course);

        return $this->ok(['message' => "{$course->title} was deleted permanently."]);
    }
}
