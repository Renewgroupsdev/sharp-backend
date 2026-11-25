<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\ApiController;

use App\Enums\AttendanceStatus;
use App\Enums\Role;
use App\Http\Requests\MarkAttendanceRequest;
use App\Http\Resources\AttendanceResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends ApiController
{
    public function __construct(protected readonly AttendanceService $attendance) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $publicId = $request->query('student_id');

        if ($publicId) {
            $student = User::students()->where('public_id', $publicId)->first()
                ?? abort(404, 'Student not found.');
        } elseif ($actor->role === Role::Student) {
            $student = $actor;
        } else {
            abort(422, 'student_id is required.');
        }

        $this->authorize('viewAttendance', $student);

        $result = $this->attendance->forStudent($student);

        return $this->ok([
            'student' => new UserResource($student),
            'rate' => $result['rate'],
            'records' => AttendanceResource::collection($result['records']),
        ]);
    }

    public function store(MarkAttendanceRequest $request): JsonResponse
    {
        $student = User::students()->where('public_id', $request->validated('student_id'))->first()
            ?? abort(404, 'Student not found.');

        $message = $this->attendance->mark(
            $request->user(),
            $student,
            AttendanceStatus::from($request->validated('status')),
            $request->validated('reason'),
            $request->validated('date'),
        );

        return $this->ok(['message' => $message]);
    }
}
