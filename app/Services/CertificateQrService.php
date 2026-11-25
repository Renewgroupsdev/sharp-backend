<?php

namespace App\Services;

use App\Enums\CertificateStatus;
use App\Models\Certificate;
use App\Models\CertificateSetting;
use App\Models\Course;
use App\Models\User;
use App\Support\AvatarStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The certificate QR codes: one per student per enrolled course (a student is enrolled in every course of their batch). The QR
 * holds a random token; what a scan shows depends on how the course stands: completed (100%) shows the certificate, whether
 * or not the office has issued its number yet; a cancelled certificate says so; anything else says the course is not completed.
 */
class CertificateQrService
{
    /**
     * The student's courses with their QR token and standing, creating any token that is missing.
     *
     * @return list<array<string, mixed>>
     */
    public function forStudent(User $student): array
    {
        $courses = Course::query()
            ->whereIn('id', DB::table('batch_course')->where('batch_id', $student->batch_id ?? 0)->select('course_id'))
            ->orderBy('title')->get(['id', 'title']);
        if ($courses->isEmpty()) {
            return [];
        }

        $tokens = DB::table('certificate_qr_codes')->where('user_id', $student->id)->whereIn('course_id', $courses->pluck('id'))->pluck('token', 'course_id');
        foreach ($courses as $course) {
            if (! $tokens->has($course->id)) {
                $token = Str::random(32);
                DB::table('certificate_qr_codes')->insertOrIgnore(['user_id' => $student->id, 'course_id' => $course->id, 'token' => $token, 'created_at' => now(), 'updated_at' => now()]);
                $tokens[$course->id] = DB::table('certificate_qr_codes')->where(['user_id' => $student->id, 'course_id' => $course->id])->value('token');
            }
        }

        return $courses->map(function (Course $course) use ($student, $tokens) {
            $standing = $this->standing($student->id, $course->id);

            return [
                'course_id' => $course->id,
                'course' => $course->title,
                'token' => $tokens[$course->id],
                'status' => $standing['status'],
                'progress' => $standing['progress'],
                'number' => $standing['certificate']?->number,
                'issued_at' => $standing['certificate']?->issued_at,
            ];
        })->all();
    }

    /**
     * What the public page shows for a scanned token, or null when the token is unknown.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(string $token): ?array
    {
        $row = DB::table('certificate_qr_codes')->where('token', $token)->first();
        if (! $row) {
            return null;
        }
        $student = User::query()->find($row->user_id);
        $course = Course::query()->withTrashed()->find($row->course_id);
        if (! $student || ! $course) {
            return null;
        }

        $standing = $this->standing($student->id, $course->id);
        $out = ['status' => $standing['status'], 'name' => $student->name, 'course' => $course->title, 'progress' => $standing['progress']];

        $cert = $standing['certificate'];
        if ($standing['status'] === 'issued' && $cert) {
            $out['official'] = true;
            $out['certificate'] = [
                'name' => $student->name,
                'course' => $course->title,
                'score' => $cert->score,
                'number' => $cert->number,
                'issued_at' => $cert->issued_at,
                'issue_date' => $cert->issue_date?->toDateString(),
                'photo' => AvatarStore::url($student->avatar_url),
                'design' => $cert->design,
            ];
        } elseif ($standing['status'] === 'completed') {
            // Finished the course (100%): show the certificate now, with the programme's current design. Its number comes when the office issues it.
            $pending = Certificate::query()->where(['user_id' => $student->id, 'course_id' => $course->id])->orderByDesc('id')->first();
            $score = DB::table('quiz_results as q')->join('assessments as a', 'a.id', '=', 'q.assessment_id')
                ->where('q.student_id', $student->id)->where('a.course', $course->title)->selectRaw('ROUND(AVG(q.result_percent)) AS pct')->value('pct');
            $done = DB::table('learning_progress')->where(['user_id' => $student->id, 'course_id' => $course->id])->value('updated_at');
            $out['official'] = false;
            $out['certificate'] = [
                'name' => $student->name,
                'course' => $course->title,
                'score' => $pending?->score_override ?? ($score !== null ? (int) $score : null),
                'number' => null,
                'issued_at' => null,
                'issue_date' => $pending?->issue_date?->toDateString() ?? ($done ? substr((string) $done, 0, 10) : now()->toDateString()),
                'photo' => AvatarStore::url($student->avatar_url),
                'design' => array_merge(CertificateSetting::current()->design(), $pending?->design_override ?? []),
            ];
        } elseif ($standing['status'] === 'cancelled' && $cert) {
            $out['cancelled_at'] = $cert->cancelled_at;
        }

        return $out;
    }

    /** @return array{status: string, progress: int, certificate: ?Certificate} */
    private function standing(int $userId, int $courseId): array
    {
        $progress = (int) DB::table('learning_progress')->where(['user_id' => $userId, 'course_id' => $courseId])->value('progress');
        $certs = Certificate::query()->where(['user_id' => $userId, 'course_id' => $courseId])->orderByDesc('id')->get();
        $issued = $certs->first(fn (Certificate $c) => $c->status === CertificateStatus::Issued);
        $cancelled = $certs->first(fn (Certificate $c) => $c->status === CertificateStatus::Cancelled);

        return match (true) {
            $issued !== null => ['status' => 'issued', 'progress' => $progress, 'certificate' => $issued],
            $cancelled !== null => ['status' => 'cancelled', 'progress' => $progress, 'certificate' => $cancelled],
            $progress >= 100 => ['status' => 'completed', 'progress' => $progress, 'certificate' => null],
            default => ['status' => 'in_progress', 'progress' => $progress, 'certificate' => null],
        };
    }
}
