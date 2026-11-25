<?php

namespace App\Http\Controllers\Api\Web;

use App\Enums\CertificateStatus;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\CancelCertificateRequest;
use App\Http\Requests\ReviewCertificateRequest;
use App\Http\Requests\UpdateCertificateRequest;
use App\Http\Resources\CertificateResource;
use App\Models\Certificate;
use App\Services\CertificateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Certificates of finished courses. Reads are staff + mentors (a mentor's own batch); a mentor may also review. Creating,
 * issuing, rejecting and cancelling are gated to staff by the route group. Students read theirs through /my/certificates,
 * and anyone can check a number at /verify/{number}.
 */
class CertificateController extends ApiController
{
    public function __construct(private readonly CertificateService $certificates) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'course_id' => ['nullable', 'integer'],
            'batch_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(CertificateStatus::class)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->ok(['certificates' => CertificateResource::collection($this->certificates->list($request->user(), $filters))]);
    }

    /** The signed-in student's own certificates. */
    public function mine(Request $request): JsonResponse
    {
        return $this->ok(['certificates' => CertificateResource::collection($this->certificates->forStudent($request->user()))]);
    }

    /** The signed-in student's certificate QR codes: one per enrolled course. */
    public function qrCodes(Request $request, \App\Services\CertificateQrService $qr): JsonResponse
    {
        return $this->ok(['codes' => $qr->forStudent($request->user())]);
    }

    /** Staff: one student's certificate QR codes (a mentor only for their own batch). `student` is a public id, which has slashes, so it is a query parameter. */
    public function studentQrCodes(Request $request, \App\Services\CertificateQrService $qr): JsonResponse
    {
        $publicId = $request->validate(['student' => ['required', 'string', 'max:60']])['student'];
        $student = \App\Models\User::query()->where('public_id', $publicId)->where('role', \App\Enums\Role::Student->value)->firstOrFail();
        abort_unless(\App\Support\StudentScope::allows($request->user(), $student), 403, 'You cannot view this student.');

        return $this->ok(['codes' => $qr->forStudent($student)]);
    }

    /** Public: what a scanned QR shows (the certificate when the course is done, otherwise how far the course is). */
    public function scan(string $token, \App\Services\CertificateQrService $qr): JsonResponse
    {
        $result = $qr->resolve($token);

        return $result ? $this->ok($result) : $this->ok(['status' => 'not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['student_id' => ['required', 'string', 'max:40'], 'course_id' => ['required', 'integer', 'exists:courses,id']]);

        return $this->ok(['certificate' => new CertificateResource($this->certificates->create($request->user(), $data['student_id'], (int) $data['course_id']))], 201);
    }

    /** Edit the details of a certificate that is not issued yet. */
    public function update(UpdateCertificateRequest $request, Certificate $certificate): JsonResponse
    {
        return $this->ok(['certificate' => new CertificateResource($this->certificates->update($request->user(), $certificate, $request->validated()))]);
    }

    public function review(ReviewCertificateRequest $request, Certificate $certificate): JsonResponse
    {
        $data = $request->validated();

        return $this->ok(['certificate' => new CertificateResource($this->certificates->review($request->user(), $certificate, $data['decision'], $data['note'] ?? null))]);
    }

    public function issue(Request $request, Certificate $certificate): JsonResponse
    {
        $override = $request->validate(['override' => ['sometimes', 'boolean']])['override'] ?? false;

        return $this->ok(['certificate' => new CertificateResource($this->certificates->issue($request->user(), $certificate, (bool) $override))]);
    }

    public function reject(Request $request, Certificate $certificate): JsonResponse
    {
        return $this->ok(['certificate' => new CertificateResource($this->certificates->reject($request->user(), $certificate))]);
    }

    /** Deletes a certificate that is not issued; an issued one must be cancelled first. */
    public function delete(Request $request, Certificate $certificate): JsonResponse
    {
        $this->certificates->delete($request->user(), $certificate);

        return $this->ok(['message' => 'Certificate deleted.']);
    }

    /** Cancels an issued certificate. A reason is required: a listed one, or Other with a note. */
    public function cancel(CancelCertificateRequest $request, Certificate $certificate): JsonResponse
    {
        $data = $request->validated();

        return $this->ok(['certificate' => new CertificateResource($this->certificates->cancel($request->user(), $certificate, $data['reason'], $data['note'] ?? null))]);
    }

    /** The reasons offered when cancelling, so the web form and the API never drift apart. */
    public function cancelReasons(): JsonResponse
    {
        return $this->ok(['reasons' => Certificate::CANCEL_REASONS]);
    }

    /** Public: is this certificate number genuine? Returns only the name, course and dates. */
    public function verify(string $number): JsonResponse
    {
        $certificate = $this->certificates->findByNumber($number);
        if (! $certificate) {
            return $this->ok(['valid' => false, 'status' => 'NotFound'], 404);
        }

        return $this->ok([
            'valid' => $certificate->status === CertificateStatus::Issued,
            'status' => $certificate->status,
            'number' => $certificate->number,
            'name' => $certificate->student->name,
            'course' => $certificate->course->title,
            'issued_at' => $certificate->issued_at,
            'cancelled_at' => $certificate->cancelled_at,
        ]);
    }
}
