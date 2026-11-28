<?php

namespace App\Services;

use App\Support\StudentScope;
use App\Enums\CertificateStatus;
use App\Enums\Role;
use App\Models\Certificate;
use App\Models\CertificateSetting;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Certificates. A Pending certificate is requested automatically when a student reaches 100% in a course (or created by
 * staff). The student's mentor may recommend it; staff issue it against the minimum attendance and score, or reject it.
 * An issued certificate keeps a copy of the design and marks it was issued with, and can later be cancelled (with a reason).
 */
class CertificateService
{
    private const WITH = ['student:id,public_id,name,org,batch_id', 'student.batch:id,name', 'course:id,title', 'mentorReviewer:id,name'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {}

    /** Idempotent: the student has one certificate per course, whatever its status. */
    public function request(User $student, int $courseId): Certificate
    {
        $certificate = Certificate::firstOrCreate(['user_id' => $student->id, 'course_id' => $courseId]);
        if ($certificate->wasRecentlyCreated) {
            $certificate->loadMissing('course:id,title');
            $this->notifications->notifyStaff('certificate_requested', $student->batch_id, [
                'student' => $student->name, 'batch' => $student->batch?->name ?? 'no batch', 'course' => $certificate->course?->title,
            ], ['url' => '/app/certificates']);
        }

        return $certificate;
    }

    /**
     * @param  array{course_id?: int, batch_id?: int, status?: string, q?: string}  $filters
     * @return Collection<int, Certificate>
     */
    public function list(User $actor, array $filters = []): Collection
    {
        $rows = Certificate::query()->with(self::WITH)
            ->when($actor->role === Role::Mentor, fn (Builder $q) => $q->whereHas('student', fn (Builder $s) => $s->whereIn('batch_id', StudentScope::batchIds($actor))))
            ->when($actor->role === Role::Institution, fn (Builder $q) => $q->whereHas('student', fn (Builder $s) => $s->where('college_id', $actor->college_id ?? 0)))
            ->when(! empty($filters['course_id']), fn (Builder $q) => $q->where('course_id', $filters['course_id']))
            ->when(! empty($filters['batch_id']), fn (Builder $q) => $q->whereHas('student', fn (Builder $s) => $s->where('batch_id', $filters['batch_id'])))
            ->when(! empty($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['q']), fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('number', 'ilike', "%{$filters['q']}%")
                ->orWhereHas('student', fn (Builder $s) => $s->where('name', 'ilike', "%{$filters['q']}%")->orWhere('public_id', 'ilike', "%{$filters['q']}%"))
                ->orWhereHas('course', fn (Builder $c) => $c->where('title', 'ilike', "%{$filters['q']}%"))))
            ->orderByDesc('id')->limit(1000)->get();

        return $this->prepare($rows);
    }

    /** A student's own certificates, newest first. */
    public function forStudent(User $student): Collection
    {
        return $this->prepare(Certificate::query()->with(self::WITH)->where('user_id', $student->id)->orderByDesc('id')->get());
    }

    /** Staff create a Pending certificate for a student who has not finished the course by the usual route. */
    public function create(User $actor, string $studentPublicId, int $courseId, ?int $templateId = null): Certificate
    {
        $student = User::query()->where('public_id', $studentPublicId)->where('role', Role::Student->value)->firstOrFail();
        $course = Course::query()->findOrFail($courseId);

        $certificate = $this->request($student, $course->id);
        if (! $certificate->wasRecentlyCreated) {
            if ($certificate->status !== CertificateStatus::Rejected) {
                throw ValidationException::withMessages(['student_id' => "{$student->name} already has a certificate for {$course->title}."]);
            }
            $certificate->update(['status' => CertificateStatus::Pending, 'mentor_decision' => null, 'mentor_note' => null]); // a rejected one is reopened
        }

        // A template is copied onto the certificate, so editing or deleting the template later never changes it.
        $template = $templateId ? \App\Models\CertificateTemplate::query()->find($templateId) : null;
        if ($template) {
            $certificate->update(['design_override' => $template->design]);
        }

        $this->audit->record($actor, 'Created certificate', $student->public_id, $course->title.($template ? " · template {$template->name}" : ''));

        return $this->one($certificate);
    }

    /** The mentor's recommendation (approved / returned with a note); staff may record one too. It does not issue anything. */
    public function review(User $actor, Certificate $certificate, string $decision, ?string $note): Certificate
    {
        $certificate->loadMissing('student');
        abort_if($actor->role === Role::Institution, 403, 'An institute can view certificates but not review them.');
        abort_if($actor->role === Role::Mentor && ! StudentScope::ownsBatch($actor, $certificate->student->batch_id), 403, 'You can only review your assigned students.');
        if ($certificate->status !== CertificateStatus::Pending) {
            throw ValidationException::withMessages(['status' => 'Only a certificate that is still pending can be reviewed.']);
        }

        $certificate->update([
            'mentor_decision' => $decision, 'mentor_note' => $note,
            'mentor_reviewed_by' => $actor->id, 'mentor_reviewed_at' => now(),
        ]);
        $this->audit->record($actor, $decision === 'approved' ? 'Approved certificate' : 'Returned certificate', $certificate->student->public_id, $note);
        $certificate->loadMissing('course:id,title');
        $this->notifications->notifyStaff('certificate_recommended', null, [
            'mentor' => $actor->name, 'decision' => $decision === 'approved' ? 'approved' : 'returned', 'student' => $certificate->student->name,
            'course' => $certificate->course?->title, 'note' => $note ? ' Note: '.$note : '',
        ], ['url' => '/app/certificates'], $actor->id);

        return $this->one($certificate);
    }

    /** Issues the certificate: below the minimum attendance or score it needs `$override`, which is recorded. */
    public function issue(User $actor, Certificate $certificate, bool $override = false): Certificate
    {
        if ($certificate->status === CertificateStatus::Issued) {
            return $this->one($certificate);
        }
        if ($certificate->status === CertificateStatus::Cancelled) {
            throw ValidationException::withMessages(['status' => 'A cancelled certificate cannot be issued again. Delete it and create a new one.']);
        }

        $settings = CertificateSetting::current();
        $metrics = $this->effective($certificate, $this->metrics(collect([$certificate->loadMissing(['student', 'course'])]), $settings)[$certificate->id], $settings);
        if (! $metrics['ok'] && ! $override) {
            throw ValidationException::withMessages(['eligibility' => $this->shortfall($certificate->student->name, $metrics)]);
        }

        DB::transaction(function () use ($actor, $certificate, $settings, $metrics) {
            $certificate->update([
                'status' => CertificateStatus::Issued,
                'number' => $certificate->number ?? sprintf('%s-%s-%05d', $settings->prefix, now()->format('Y'), $certificate->id),
                'issued_by' => $actor->id,
                'issued_at' => $certificate->issue_date ? $certificate->issue_date->copy()->setTimeFromTimeString(now()->format('H:i:s')) : now(),
                'score' => $metrics['score'],
                'attendance' => $metrics['attendance'],
                'eligibility_override' => ! $metrics['ok'],
                'design' => $this->designFor($certificate, $settings),
            ]);
        });

        $certificate->loadMissing(self::WITH);
        $this->notifications->notify('certificate_issued', $certificate->user_id,
            ['course' => $certificate->course->title, 'number' => $certificate->number],
            ['course_id' => $certificate->course_id, 'url' => '/app/certificates']);
        $this->audit->record($actor, 'Issued certificate', $certificate->number, "{$certificate->student->name} · {$certificate->course->title}".($metrics['ok'] ? '' : ' (eligibility override)'));

        return $this->one($certificate);
    }

    public function reject(User $actor, Certificate $certificate): Certificate
    {
        if (in_array($certificate->status, [CertificateStatus::Issued, CertificateStatus::Cancelled], true)) {
            abort(422, 'An issued certificate cannot be rejected. Cancel it instead.');
        }

        $certificate->update(['status' => CertificateStatus::Rejected, 'issued_by' => $actor->id]);
        $certificate->loadMissing(self::WITH);
        $this->audit->record($actor, 'Rejected certificate', $certificate->student->public_id, $certificate->course->title);
        $this->notifications->notify('certificate_rejected', $certificate->user_id, ['course' => $certificate->course->title], ['course_id' => $certificate->course_id, 'url' => '/app/certificates']);

        return $this->one($certificate);
    }

    /**
     * Deletes a certificate that is not currently issued (pending, rejected or cancelled). An issued one must be cancelled first,
     * so a number that was valid never just disappears. The deletion is written to the audit log.
     */
    public function delete(User $actor, Certificate $certificate): void
    {
        if ($certificate->status === CertificateStatus::Issued) {
            throw ValidationException::withMessages(['status' => 'An issued certificate cannot be deleted. Cancel it first.']);
        }

        $certificate->loadMissing(self::WITH);
        $detail = "{$certificate->student->name} · {$certificate->course->title} · {$certificate->status->value}";
        $target = $certificate->number ?? $certificate->student->public_id;
        $certificate->delete();
        $this->audit->record($actor, 'Deleted certificate', $target, $detail);
    }

    /**
     * Withdraws an issued certificate, with a reason (a chosen one, or Other with a note). Its number stays on record, and
     * verification then reports it as cancelled. The student is told why.
     */
    public function cancel(User $actor, Certificate $certificate, string $reason, ?string $note): Certificate
    {
        if ($certificate->status !== CertificateStatus::Issued) {
            throw ValidationException::withMessages(['status' => 'Only an issued certificate can be cancelled.']);
        }

        $certificate->update([
            'status' => CertificateStatus::Cancelled, 'cancelled_by' => $actor->id, 'cancelled_at' => now(),
            'cancel_reason' => $reason, 'cancel_note' => $note,
        ]);
        $certificate->loadMissing(self::WITH);

        $why = $note ? "{$reason}: {$note}" : $reason;
        $this->notifications->notify('certificate_cancelled', $certificate->user_id,
            ['course' => $certificate->course->title, 'number' => $certificate->number, 'reason' => $why],
            ['course_id' => $certificate->course_id, 'url' => '/app/certificates']);
        $this->audit->record($actor, 'Cancelled certificate', $certificate->number, "{$certificate->student->name} · {$certificate->course->title} · {$why}");

        return $this->one($certificate);
    }

    /** For the public verification page: the certificate with this number, or null. */
    public function findByNumber(string $number): ?Certificate
    {
        return Certificate::query()->with(['student:id,public_id,name', 'course:id,title'])->where('number', $number)->first();
    }

    /**
     * Attendance and quiz score of each certificate's student against the minimums, computed in two queries.
     * A student with no course dates or no quiz results is not held back by that measure.
     *
     * @param  Collection<int, Certificate>  $certificates
     * @return array<int, array{attendance: ?int, score: ?int, min_attendance: int, min_score: int, ok: bool}>
     */
    public function metrics(Collection $certificates, ?CertificateSetting $settings = null): array
    {
        $settings ??= CertificateSetting::current();
        $students = $certificates->pluck('user_id')->unique()->all();

        // Course attendance: days attended out of the working days between the course (batch) start and end dates.
        $attendance = collect((new AttendanceSummary())->ratesFor($students));
        $scores = DB::table('quiz_results as q')->join('assessments as a', 'a.id', '=', 'q.assessment_id')
            ->whereIn('q.student_id', $students)->groupBy('q.student_id', 'a.course')
            ->selectRaw('q.student_id, a.course, ROUND(AVG(q.result_percent)) AS pct')->get()
            ->groupBy('student_id')->map(fn ($rows) => $rows->pluck('pct', 'course'));

        $out = [];
        foreach ($certificates as $c) {
            $title = $c->course->title ?? Course::query()->whereKey($c->course_id)->value('title');
            $att = $attendance->has($c->user_id) ? (int) $attendance[$c->user_id] : null;
            $score = isset($scores[$c->user_id][$title]) ? (int) $scores[$c->user_id][$title] : null;
            $out[$c->id] = [
                'attendance' => $att, 'score' => $score,
                'min_attendance' => $settings->min_attendance, 'min_score' => $settings->min_score,
                'ok' => ($att === null || $att >= $settings->min_attendance) && ($score === null || $score >= $settings->min_score),
            ];
        }

        return $out;
    }

    /** Attaches the live eligibility and the current design to each row for the resource. */
    private function prepare(Collection $certificates): Collection
    {
        $settings = CertificateSetting::current();
        $metrics = $this->metrics($certificates, $settings);
        foreach ($certificates as $c) {
            $c->setAttribute('score_live', $metrics[$c->id]['score']);
            $c->setAttribute('metrics', $this->effective($c, $metrics[$c->id], $settings));
            $c->setAttribute('live_design', $this->designFor($c, $settings));
        }

        return $certificates;
    }

    /** The marks a certificate is judged on: a score staff entered replaces the computed one. */
    private function effective(Certificate $c, array $m, CertificateSetting $settings): array
    {
        if ($c->score_override !== null) {
            $m['score'] = $c->score_override;
        }
        $m['min_attendance'] = $c->min_attendance_override ?? $settings->min_attendance;
        $m['ok'] = ($m['attendance'] === null || $m['attendance'] >= $m['min_attendance']) && ($m['score'] === null || $m['score'] >= $m['min_score']);

        return $m;
    }

    /** The programme design with this certificate's own wording and signature laid over it. */
    private function designFor(Certificate $c, CertificateSetting $settings): array
    {
        return array_merge($settings->design(), $c->design_override ?? []);
    }

    /**
     * Staff edit a certificate that is not issued yet: the date it will carry, its score, its wording and signature. Only
     * what differs from the computed score and the programme settings is stored, so a field set back to the default is
     * cleared. An issued certificate is locked; cancel it and create a new one instead.
     *
     * @param  array{issue_date?: ?string, score?: ?int, min_attendance?: ?int, design?: ?array<string, mixed>}  $data
     */
    public function update(User $actor, Certificate $certificate, array $data): Certificate
    {
        if (! in_array($certificate->status, [CertificateStatus::Pending, CertificateStatus::Rejected], true)) {
            throw ValidationException::withMessages(['status' => 'An issued certificate is locked. Cancel it and create a new one.']);
        }

        $settings = CertificateSetting::current();
        $fields = [];
        if (array_key_exists('issue_date', $data)) {
            $fields['issue_date'] = $data['issue_date'];
        }
        if (array_key_exists('score', $data)) {
            $live = $this->metrics(collect([$certificate->loadMissing(['student', 'course'])]), $settings)[$certificate->id]['score'];
            $fields['score_override'] = ($data['score'] === null || $data['score'] === $live) ? null : $data['score'];
        }
        if (array_key_exists('min_attendance', $data)) {
            $fields['min_attendance_override'] = ($data['min_attendance'] === null || $data['min_attendance'] === $settings->min_attendance) ? null : $data['min_attendance'];
        }
        if (array_key_exists('design', $data)) {
            $base = $settings->design();
            $changed = collect($data['design'] ?? [])
                ->only(['template', 'type', 'title', 'body', 'accent', 'signature_name', 'signature_role', 'signature_image', 'show_profile', 'show_qr', 'show_logo', 'logo_position', 'logo_image', 'logos'])
                ->map(fn ($v) => $v === '' ? null : $v)
                ->filter(fn ($v, $k) => $v !== ($base[$k] ?? null))
                ->all();
            $fields['design_override'] = $changed ?: null;
        }

        $certificate->update($fields);
        $certificate->loadMissing(self::WITH);
        $this->audit->record($actor, 'Updated certificate', $certificate->student->public_id, $certificate->course->title);

        return $this->one($certificate);
    }

    private function one(Certificate $certificate): Certificate
    {
        return $this->prepare(collect([$certificate->refresh()->load(self::WITH)]))->first();
    }

    /** @param array{attendance: ?int, score: ?int, min_attendance: int, min_score: int} $m */
    private function shortfall(string $name, array $m): string
    {
        $parts = [];
        if ($m['attendance'] !== null && $m['attendance'] < $m['min_attendance']) {
            $parts[] = "attendance {$m['attendance']}% (minimum {$m['min_attendance']}%)";
        }
        if ($m['score'] !== null && $m['score'] < $m['min_score']) {
            $parts[] = "score {$m['score']}% (minimum {$m['min_score']}%)";
        }

        return "{$name} is below the minimum: ".implode(' and ', $parts).'. Issue it with the eligibility override to continue.';
    }
}
