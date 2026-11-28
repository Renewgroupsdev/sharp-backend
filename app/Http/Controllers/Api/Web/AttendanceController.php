<?php

namespace App\Http\Controllers\Api\Web;

use App\Enums\AttendanceStatus;
use App\Http\Controllers\Api\Mobile\AttendanceController as MobileAttendanceController;
use App\Http\Requests\BulkMarkAttendanceRequest;
use App\Http\Resources\AttendanceListResource;
use App\Models\User;
use App\Services\AttendanceSummary;
use App\Support\StudentScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Same behaviour as mobile; roster per college comes from GET web/users?role=student&college_id=. Adds the staff list. */
class AttendanceController extends MobileAttendanceController
{
    /** Marks several students the same way for one day (the manual tab's bulk action). */
    public function bulk(BulkMarkAttendanceRequest $request): JsonResponse
    {
        $data = $request->validated();
        $result = $this->attendance->markMany($request->user(), $data['student_ids'], AttendanceStatus::from($data['status']), $data['reason'] ?? null, $data['date'] ?? null);

        return $this->ok(['marked' => $result['marked'], 'message' => "{$result['marked']} students marked ".strtolower($data['status']).'.']);
    }

    /** Month-by-month and per-course attendance percentages: your own, or a student's you may see (`?student_id=` public id). */
    public function summary(Request $request, AttendanceSummary $summary): JsonResponse
    {
        $actor = $request->user();
        $publicId = (string) $request->query('student_id', '');
        $student = $publicId === '' ? $actor : User::students()->where('public_id', $publicId)->firstOrFail();
        abort_unless($student->id === $actor->id || StudentScope::allows($actor, $student), 403, 'You can only view your own students.');

        return $this->ok($summary->for($student));
    }

    /** Attendance of many students (staff and mentors; a mentor only sees their own batch). Defaults to the last 90 days. */
    public function list(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'batch_id' => ['nullable', 'integer'],
            'college_id' => ['nullable', 'integer'],
            'student_id' => ['nullable', 'string'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'status' => ['nullable', Rule::enum(AttendanceStatus::class)],
        ]);

        return $this->ok(['records' => AttendanceListResource::collection($this->attendance->list($request->user(), $filters))]);
    }
}
