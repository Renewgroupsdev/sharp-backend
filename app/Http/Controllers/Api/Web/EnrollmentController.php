<?php

namespace App\Http\Controllers\Api\Web;

use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\StoreEnrollmentRequest;
use App\Http\Resources\EnrollmentEventResource;
use App\Http\Resources\EnrollmentResource;
use App\Http\Resources\EnrollmentStudentResource;
use App\Http\Resources\UserResource;
use App\Services\EnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Enrollment management (courses run for batches). Reads are staff + mentors; writes are gated by the route group. */
class EnrollmentController extends ApiController
{
    public function __construct(private readonly EnrollmentService $enrollments) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'course_id' => ['nullable', 'integer'],
            'batch_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(EnrollmentStatus::class)],
        ]);

        return $this->ok(['enrollments' => EnrollmentResource::collection($this->enrollments->list($request->user(), $filters))]);
    }

    /** The students and mentors behind the two counts; loaded when a count is opened. */
    public function members(Request $request, int $enrollment): JsonResponse
    {
        $members = $this->enrollments->members($request->user(), $enrollment);

        return $this->ok([
            'students' => UserResource::collection($members['students']),
            'mentors' => UserResource::collection($members['mentors']),
        ]);
    }

    /** One enrollment in full: students with progress, attendance, quiz average and certificate; mentors; history. */
    public function detail(Request $request, int $enrollment): JsonResponse
    {
        $d = $this->enrollments->detail($request->user(), $enrollment);

        return $this->ok([
            'enrollment' => new EnrollmentResource($d['enrollment']),
            'lessons_total' => $d['lessons_total'],
            'summary' => $d['summary'],
            'students' => EnrollmentStudentResource::collection($d['students']),
            'mentors' => UserResource::collection($d['mentors']),
            'history' => EnrollmentEventResource::collection($d['history']),
        ]);
    }

    /** The trail of courses added to / removed from batches, optionally for one course or batch. */
    public function history(Request $request): JsonResponse
    {
        $filters = $request->validate(['course_id' => ['nullable', 'integer'], 'batch_id' => ['nullable', 'integer']]);

        return $this->ok(['history' => EnrollmentEventResource::collection($this->enrollments->history($request->user(), $filters))]);
    }

    public function store(StoreEnrollmentRequest $request): JsonResponse
    {
        return $this->enrolled($request, [(int) $request->validated('course_id')], array_map('intval', $request->validated('batch_ids')), 'manual');
    }

    /** Several courses × several batches in one go. */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'course_ids' => ['required', 'array', 'min:1', 'max:100'],
            'course_ids.*' => ['integer', 'distinct', Rule::exists('courses', 'id')->whereNull('deleted_at')],
            'batch_ids' => ['required', 'array', 'min:1', 'max:100'],
            'batch_ids.*' => ['integer', 'distinct', 'exists:batches,id'],
        ]);

        return $this->enrolled($request, array_map('intval', $data['course_ids']), array_map('intval', $data['batch_ids']), 'bulk');
    }

    /** Give the target batches every course the selected enrollments run. */
    public function copy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enrollment_ids' => ['required', 'array', 'min:1', 'max:500'],
            'enrollment_ids.*' => ['integer', 'distinct', 'exists:batch_course,id'],
            'batch_ids' => ['required', 'array', 'min:1', 'max:100'],
            'batch_ids.*' => ['integer', 'distinct', 'exists:batches,id'],
        ]);

        $created = $this->enrollments->copy($request->user(), array_map('intval', $data['enrollment_ids']), array_map('intval', $data['batch_ids']));

        return $this->ok(['created' => $created]);
    }

    /** Remove several enrollments at once. */
    public function removeMany(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct', 'exists:batch_course,id'],
        ]);

        return $this->ok(['removed' => $this->enrollments->removeMany($request->user(), array_map('intval', $data['ids']))]);
    }

    /** Enrol from rows of (course, batch). `dry_run` previews the result without writing. */
    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:'.EnrollmentService::MAX_IMPORT_ROWS],
            'rows.*.course' => ['nullable', 'string', 'max:200'],
            'rows.*.batch' => ['nullable', 'string', 'max:100'],
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        return $this->ok($this->enrollments->import($request->user(), $data['rows'], (bool) ($data['dry_run'] ?? false)));
    }

    /** A Pending certificate for every student who finished the course and has none yet. */
    public function certificates(Request $request, int $enrollment): JsonResponse
    {
        return $this->ok(['created' => $this->enrollments->generateCertificates($request->user(), $enrollment)]);
    }

    public function destroy(Request $request, int $enrollment): JsonResponse
    {
        $this->enrollments->remove($request->user(), $enrollment);

        return $this->ok(['message' => 'Enrollment removed.']);
    }

    /** @param  list<int>  $courseIds  @param  list<int>  $batchIds */
    private function enrolled(Request $request, array $courseIds, array $batchIds, string $source): JsonResponse
    {
        $this->enrollments->enroll($request->user(), $courseIds, $batchIds, $source);

        return $this->ok(['enrollments' => EnrollmentResource::collection($this->enrollments->pairs($request->user(), $courseIds, $batchIds))], 201);
    }
}
