<?php

namespace App\Services;

use App\Enums\AttendanceRequestStatus;
use App\Enums\AttendanceStatus;
use App\Enums\CertificateStatus;
use App\Enums\CourseStatus;
use App\Enums\Role;
use App\Enums\SubmissionStatus;
use App\Enums\UserStatus;
use App\Models\AssessmentSubmission;
use App\Models\Attendance;
use App\Models\AttendanceRequest;
use App\Models\Batch;
use App\Models\Certificate;
use App\Models\College;
use App\Models\Course;
use App\Models\LearningProgress;
use App\Models\User;

/**
 * The numbers behind the admin dashboard, about the programme itself: who is learning and how far they have got, today's
 * attendance, what waits for a person to act on (certificates, attendance reports, assignments to grade).
 */
class ProgrammeOverviewService
{
    /** @return array<string, mixed> */
    public function overview(): array
    {
        $students = User::students()->where('status', '<>', UserStatus::Suspended->value);
        $studentCount = (clone $students)->count();

        $today = Attendance::query()->whereDate('att_date', today())->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        $present = (int) ($today[AttendanceStatus::Present->value] ?? 0) + (int) ($today[AttendanceStatus::Manual->value] ?? 0);
        $absent = (int) ($today[AttendanceStatus::Absent->value] ?? 0);
        $marked = $present + $absent;

        return [
            'students' => $studentCount,
            'mentors' => User::where('role', Role::Mentor->value)->where('status', '<>', UserStatus::Suspended->value)->count(),
            'institutes' => College::count(),
            'batches_active' => Batch::where('status', '<>', 'closed')->count(),
            'courses' => [
                'published' => Course::where('status', CourseStatus::Published->value)->count(),
                'total' => Course::count(),
            ],
            'learning' => [
                'avg_progress' => (int) round((float) LearningProgress::query()->avg('progress')),
                'active_7d' => LearningProgress::where('last_accessed_at', '>=', now()->subDays(7))->distinct('user_id')->count('user_id'),
                'courses_completed' => LearningProgress::where('progress', '>=', 100)->count(),
            ],
            'attendance' => [
                'date' => today()->toDateString(),
                'present' => $present,
                'absent' => $absent,
                'not_marked' => max(0, $studentCount - $marked),
                'rate' => $marked ? (int) round($present / $marked * 100) : null,
                'pending_reports' => AttendanceRequest::where('status', AttendanceRequestStatus::Pending->value)->count(),
            ],
            'assessments' => [
                'to_grade' => AssessmentSubmission::whereIn('status', [SubmissionStatus::Submitted->value, SubmissionStatus::UnderReview->value])->count(),
                'graded_7d' => AssessmentSubmission::where('status', SubmissionStatus::Graded->value)->where('graded_at', '>=', now()->subDays(7))->count(),
            ],
            'certificates' => [
                'issued' => Certificate::where('status', CertificateStatus::Issued->value)->count(),
                'pending' => Certificate::where('status', CertificateStatus::Pending->value)->count(),
            ],
        ];
    }
}
