<?php

namespace App\Http\Controllers\Api\Web;

use App\Enums\AttendanceChoice;
use App\Enums\AttendanceRequestStatus;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\StoreAttendanceRequestRequest;
use App\Http\Resources\AttendanceRequestResource;
use App\Models\AttendanceRequest;
use App\Services\AttendanceRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Student Present / Absent reports. Who may call what is set by the route groups; the service scopes the list per role. */
class AttendanceRequestController extends ApiController
{
    public function __construct(private readonly AttendanceRequestService $requests) {}

    /** `today` is the server's day, which is the day a student's report counts for. */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(AttendanceRequestStatus::class)],
            'requested_status' => ['nullable', Rule::enum(AttendanceChoice::class)],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return $this->ok([
            'today' => today()->toDateString(),
            'requests' => AttendanceRequestResource::collection($this->requests->list($request->user(), $filters)),
        ]);
    }

    public function store(StoreAttendanceRequestRequest $request): JsonResponse
    {
        $data = $request->validated();
        $saved = $this->requests->submit($request->user(), AttendanceChoice::from($data['status']), $data['reason'] ?? null);

        return $this->ok(['request' => new AttendanceRequestResource($saved)], 201);
    }

    /** Mark the final status of a report: Present or Absent. */
    public function mark(Request $request, AttendanceRequest $attendanceRequest): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(AttendanceChoice::class)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $saved = $this->requests->mark($request->user(), $attendanceRequest, AttendanceChoice::from($data['status']), $data['note'] ?? null);

        return $this->ok(['request' => new AttendanceRequestResource($saved)]);
    }
}
