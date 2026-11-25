<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\CourseResource;
use App\Models\Course;
use App\Models\CourseLesson;
use App\Services\LessonProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A student's own courses and lesson progress; the route group limits it to the student role. */
class MyLearningController extends ApiController
{
    public function __construct(private readonly LessonProgressService $progress) {}

    public function index(Request $request): JsonResponse
    {
        return $this->ok(['courses' => $this->progress->courses($request->user())]);
    }

    public function show(Request $request, Course $course): JsonResponse
    {
        $detail = $this->progress->detail($request->user(), $course);

        return $this->ok(['course' => new CourseResource($detail['course']), 'progress' => $detail['progress'], 'completed_lesson_ids' => $detail['completed_lesson_ids'], 'modules' => $detail['modules']]);
    }

    public function open(Request $request, CourseLesson $lesson): JsonResponse
    {
        return $this->ok($this->progress->open($request->user(), $lesson));
    }

    public function complete(Request $request, CourseLesson $lesson): JsonResponse
    {
        return $this->ok($this->progress->complete($request->user(), $lesson));
    }
}
