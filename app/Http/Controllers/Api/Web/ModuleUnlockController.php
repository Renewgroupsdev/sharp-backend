<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\ModuleUnlockResource;
use App\Models\CourseChapter;
use App\Models\ModuleUnlock;
use App\Models\User;
use App\Services\ModuleGateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Opening a locked module early for one student. Staff and mentors; the service limits a mentor to their own batch. */
class ModuleUnlockController extends ApiController
{
    public function __construct(private readonly ModuleGateService $gate) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['student_id' => ['nullable', 'string'], 'course_id' => ['nullable', 'integer']]);

        return $this->ok(['unlocks' => ModuleUnlockResource::collection($this->gate->list($request->user(), $filters))]);
    }

    /** Every module of the student's courses with its lock state: what can be unlocked. */
    public function overview(Request $request): JsonResponse
    {
        $data = $request->validate(['student_id' => ['required', 'string']]);
        $student = User::students()->where('public_id', $data['student_id'])->firstOrFail();

        return $this->ok(['courses' => $this->gate->overview($request->user(), $student)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'string'],
            'chapter_id' => ['required', 'integer', 'exists:course_chapters,id'],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ], ['reason.required' => 'Give the reason for unlocking early.', 'reason.min' => 'Give a little more detail about the reason.']);

        $student = User::students()->where('public_id', $data['student_id'])->firstOrFail();
        $unlock = $this->gate->unlock($request->user(), $student, CourseChapter::with('course')->findOrFail($data['chapter_id']), trim($data['reason']));

        return $this->ok(['unlock' => new ModuleUnlockResource($unlock)], 201);
    }

    public function destroy(Request $request, int $unlock): JsonResponse
    {
        $this->gate->revoke($request->user(), ModuleUnlock::findOrFail($unlock));

        return $this->ok(['message' => 'Unlock removed.']);
    }
}
