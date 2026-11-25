<?php

use App\Http\Controllers\Api\Mobile;
use App\Http\Controllers\Api\Web;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile app API   —   /api/mobile/*
|--------------------------------------------------------------------------
| Every role signs in here; per-route role middleware and policies decide access.
*/
Route::prefix('mobile')->name('mobile.')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('login', [Mobile\AuthController::class, 'login'])->middleware('throttle:login');

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [Mobile\AuthController::class, 'logout']);
            Route::post('change-password', [Mobile\AuthController::class, 'changePassword'])->middleware('active');
        });
    });

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('me', [Mobile\AuthController::class, 'me']);

        // Enum lookups
        Route::get('enums', [Mobile\EnumController::class, 'index']);
        Route::get('enums/{enum}', [Mobile\EnumController::class, 'show']);

        // Colleges
        Route::get('colleges', [Mobile\CollegeController::class, 'index']);
        Route::get('colleges/suggest-code', [Mobile\CollegeController::class, 'suggestCode']);
        Route::post('colleges', [Mobile\CollegeController::class, 'store'])->middleware('role:super_admin,admin,ob');

        // Users. public_id contains slashes (SH2026/FCM/0412), so {user} accepts them.
        Route::get('users', [Mobile\UserController::class, 'index']);
        Route::post('users', [Mobile\UserController::class, 'store']);
        Route::prefix('users/{user:public_id}')->where(['user' => '.+'])->group(function () {
            Route::post('suspend', [Mobile\UserController::class, 'suspend']);
            Route::post('activate', [Mobile\UserController::class, 'activate']);
            Route::post('reset-password', [Mobile\UserController::class, 'resetPassword']);
        });
        Route::get('users/{user:public_id}', [Mobile\UserController::class, 'show'])->where('user', '.+');

        // Attendance
        Route::get('attendance', [Mobile\AttendanceController::class, 'index']);
        Route::post('attendance', [Mobile\AttendanceController::class, 'store'])->middleware('role:super_admin,admin,ob,mentor');

        // Common Floor
        Route::get('common-floor', [Mobile\CommonFloorController::class, 'index']);
        Route::post('common-floor', [Mobile\CommonFloorController::class, 'store']);

        // Staff only
        Route::middleware('role:super_admin,admin,ob')->group(function () {
            Route::post('common-floor/{message}/{action}', [Mobile\CommonFloorController::class, 'moderate']);
            Route::get('audit-logs', [Mobile\AuditLogController::class, 'index']);
            Route::get('dashboard', Mobile\DashboardController::class);
        });
    });
});

/*
|--------------------------------------------------------------------------
| Web app API   —   /api/web/*
|--------------------------------------------------------------------------
| Same role rules as mobile (middleware + policies); only the token label differs.
*/
Route::prefix('web')->name('web.')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('login', [Web\AuthController::class, 'login'])->middleware('throttle:login');

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [Web\AuthController::class, 'logout']);
            Route::post('change-password', [Web\AuthController::class, 'changePassword'])->middleware('active');
        });
    });

    // Anyone can check a certificate number (no sign-in); it returns only the name, course and dates.
    Route::get('verify/{number}', [Web\CertificateController::class, 'verify'])->where('number', '[A-Za-z0-9\-]+')->middleware('throttle:60,1');
    // The QR of a student's course: shows the certificate once the course is completed, otherwise how far it is.
    Route::get('certificate-scan/{token}', [Web\CertificateController::class, 'scan'])->where('token', '[A-Za-z0-9]{20,40}')->middleware('throttle:60,1');

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('me', [Web\AuthController::class, 'me']);

        // Enum lookups
        Route::get('enums', [Web\EnumController::class, 'index']);
        Route::get('enums/{enum}', [Web\EnumController::class, 'show']);

        // Colleges
        Route::get('colleges', [Web\CollegeController::class, 'index']);
        Route::get('colleges/suggest-code', [Web\CollegeController::class, 'suggestCode']);
        Route::get('colleges/{college:id}', [Web\CollegeController::class, 'show'])->where('college', '\d+'); // a student's attendance page reads its own institute's geofence
        Route::middleware('role:super_admin,admin,ob')->group(function () {
            Route::post('colleges', [Web\CollegeController::class, 'store']);
            Route::put('colleges/{college:id}', [Web\CollegeController::class, 'update'])->where('college', '\d+');
            Route::delete('colleges/{college:id}', [Web\CollegeController::class, 'delete'])->where('college', '\d+');
        });

        // Batches
        Route::get('batches', [Web\BatchController::class, 'index']);
        Route::middleware('role:super_admin,admin,ob')->group(function () {
            Route::post('batches', [Web\BatchController::class, 'store']);
            Route::put('batches/{batch:id}', [Web\BatchController::class, 'update'])->where('batch', '\d+');
            Route::delete('batches/{batch:id}', [Web\BatchController::class, 'delete'])->where('batch', '\d+');
        });

        // Course categories & courses: everyone signed in can read (learners only see published courses); staff write.
        Route::get('course-categories', [Web\CourseCategoryController::class, 'index']);
        Route::get('courses', [Web\CourseController::class, 'index']);
        Route::get('courses/{course:id}', [Web\CourseController::class, 'show'])->where('course', '\d+');
        Route::middleware('role:super_admin,admin,ob')->group(function () {
            Route::post('course-categories', [Web\CourseCategoryController::class, 'store']);
            Route::put('course-categories/{category:id}', [Web\CourseCategoryController::class, 'update'])->where('category', '\d+');
            Route::delete('course-categories/{category:id}', [Web\CourseCategoryController::class, 'delete'])->where('category', '\d+');
            Route::post('courses', [Web\CourseController::class, 'store']);
            Route::put('courses/{course:id}', [Web\CourseController::class, 'update'])->where('course', '\d+');
            Route::put('courses/{course:id}/chapters', [Web\CourseController::class, 'chapters'])->where('course', '\d+');
            Route::delete('courses/{course:id}', [Web\CourseController::class, 'delete'])->where('course', '\d+');
            Route::post('courses/{course:id}/restore', [Web\CourseController::class, 'restore'])->withTrashed()->where('course', '\d+');
            Route::delete('courses/{course:id}/purge', [Web\CourseController::class, 'purge'])->withTrashed()->where('course', '\d+');
        });

        // Quiz, assignment & assessments: the question bank and quiz report hold answers and student marks, so staff and mentors only.
        Route::middleware('role:super_admin,admin,ob,mentor')->group(function () {
            Route::get('question-groups', [Web\QuestionGroupController::class, 'index']);
            Route::get('questions', [Web\QuestionController::class, 'index']);
            Route::get('quiz-results', [Web\QuizResultController::class, 'index']);
            Route::get('quiz-attempts', [Web\QuizAttemptController::class, 'index']);
            Route::get('quiz-attempts/{attempt:id}', [Web\QuizAttemptController::class, 'show'])->where('attempt', '\d+');
            Route::post('quiz-attempts/{attempt:id}/grade', [Web\QuizAttemptController::class, 'grade'])->where('attempt', '\d+');
            Route::get('submissions',[Web\SubmissionController::class, 'index']);
            Route::get('submissions/{submission:id}', [Web\SubmissionController::class, 'show'])->where('submission', '\d+');
            Route::post('submissions/{submission:id}/review', [Web\SubmissionController::class, 'review'])->where('submission', '\d+');
            Route::post('submissions/{submission:id}/grade', [Web\SubmissionController::class, 'grade'])->where('submission', '\d+');
        });
        // Dashboard counts of quiz / assignment / assessment submissions, scoped to the caller (own, batch or all).
        Route::get('submission-stats', [Web\SubmissionController::class, 'stats'])->middleware('role:student,mentor,super_admin,admin,ob');
        // Saving a student's file is for admins only; the student, mentors and OB can only open it in the viewer.
        Route::get('submission-attachments/{attachment:id}/download', [Web\SubmissionController::class, 'download'])->where('attachment', '\d+')->middleware('role:super_admin,admin')->name('submissions.attachments.download');
        Route::get('submission-attachments/{attachment:id}/view', [Web\SubmissionController::class, 'download'])->where('attachment', '\d+')->middleware('role:student,super_admin,admin,ob,mentor')->name('submissions.attachments.view');
        Route::middleware('role:super_admin,admin')->group(function () {
            Route::put('submissions/{submission:id}', [Web\SubmissionController::class, 'update'])->where('submission', '\d+');
            Route::delete('submissions/{submission:id}', [Web\SubmissionController::class, 'destroy'])->where('submission', '\d+');
        });
        Route::get('assessments', [Web\AssessmentController::class, 'index']);
        Route::get('assessments/{assessment:id}/attachment', [Web\AssessmentController::class, 'attachment'])->where('assessment', '\d+')->middleware('role:student,super_admin,admin,ob,mentor');
        Route::middleware('role:super_admin,admin,ob')->group(function () {
            Route::post('assessments/{assessment:id}/attachment', [Web\AssessmentController::class, 'uploadAttachment'])->where('assessment', '\d+');
            Route::delete('assessments/{assessment:id}/attachment', [Web\AssessmentController::class, 'deleteAttachment'])->where('assessment', '\d+');
            Route::post('question-groups', [Web\QuestionGroupController::class, 'store']);
            Route::put('question-groups/{group:id}', [Web\QuestionGroupController::class, 'update'])->where('group', '\d+');
            Route::delete('question-groups/{group:id}', [Web\QuestionGroupController::class, 'delete'])->where('group', '\d+');
            Route::post('questions', [Web\QuestionController::class, 'store']);
            Route::put('questions/{question:id}', [Web\QuestionController::class, 'update'])->where('question', '\d+');
            Route::delete('questions/{question:id}', [Web\QuestionController::class, 'delete'])->where('question', '\d+');
            Route::post('assessments', [Web\AssessmentController::class, 'store']);
            Route::put('assessments/rename-course', [Web\AssessmentController::class, 'renameCourse']);
            Route::put('assessments/{assessment:id}', [Web\AssessmentController::class, 'update'])->where('assessment', '\d+');
            Route::put('assessments/{assessment:id}/setup', [Web\AssessmentController::class, 'setup'])->where('assessment', '\d+');
            Route::delete('assessments/{assessment:id}', [Web\AssessmentController::class, 'delete'])->where('assessment', '\d+');
            Route::post('quiz-results', [Web\QuizResultController::class, 'store']);
        });

        // Sequential unlocking: a mentor or admin opens a locked module early for one student (mentors: their own batch).
        Route::middleware('role:super_admin,admin,ob,mentor')->prefix('module-unlocks')->group(function () {
            Route::get('/', [Web\ModuleUnlockController::class, 'index']);
            Route::get('overview', [Web\ModuleUnlockController::class, 'overview']);
            Route::post('/', [Web\ModuleUnlockController::class, 'store']);
            Route::delete('{unlock}', [Web\ModuleUnlockController::class, 'destroy'])->where('unlock', '\d+');
        });

        // Learning management: progress per enrolled student and course (ids travel in the body / query, public ids contain slashes).
        Route::get('learning', [Web\LearningController::class, 'index'])->middleware('role:super_admin,admin,ob,mentor');
        Route::get('learning/report', [Web\LearningController::class, 'report'])->middleware('role:super_admin,admin,ob,mentor');
        Route::middleware('role:super_admin,admin,ob')->group(function () {
            Route::put('learning', [Web\LearningController::class, 'save']);
            Route::delete('learning', [Web\LearningController::class, 'reset']);
        });

        // Enrollment management: a course run for a batch. Staff and mentors read (mentors only their own batch); staff write.
        Route::middleware('role:super_admin,admin,ob,mentor')->group(function () {
            Route::get('enrollments', [Web\EnrollmentController::class, 'index']);
            Route::get('enrollments/history', [Web\EnrollmentController::class, 'history']);
            Route::get('enrollments/{enrollment}/members', [Web\EnrollmentController::class, 'members'])->where('enrollment', '\d+');
            Route::get('enrollments/{enrollment}/detail', [Web\EnrollmentController::class, 'detail'])->where('enrollment', '\d+');
            Route::get('certificates', [Web\CertificateController::class, 'index']);
            Route::get('certificate-qr', [Web\CertificateController::class, 'studentQrCodes']);
            // The mentor recommends (approve / return with a note); staff issue.
            Route::post('certificates/{certificate:id}/review', [Web\CertificateController::class, 'review'])->where('certificate', '\d+');
        });
        Route::middleware('role:super_admin,admin,ob')->group(function () {
            Route::post('enrollments', [Web\EnrollmentController::class, 'store']);
            Route::post('enrollments/bulk', [Web\EnrollmentController::class, 'bulk']);
            Route::post('enrollments/copy', [Web\EnrollmentController::class, 'copy']);
            Route::post('enrollments/remove', [Web\EnrollmentController::class, 'removeMany']);
            Route::post('enrollments/import', [Web\EnrollmentController::class, 'import']);
            Route::post('enrollments/{enrollment}/certificates', [Web\EnrollmentController::class, 'certificates'])->where('enrollment', '\d+');
            Route::delete('enrollments/{enrollment}', [Web\EnrollmentController::class, 'destroy'])->where('enrollment', '\d+');
            Route::post('certificates', [Web\CertificateController::class, 'store']);
            Route::put('certificates/{certificate:id}', [Web\CertificateController::class, 'update'])->where('certificate', '\d+');
            Route::delete('certificates/{certificate:id}', [Web\CertificateController::class, 'delete'])->where('certificate', '\d+');
            Route::post('certificates/{certificate:id}/cancel', [Web\CertificateController::class, 'cancel'])->where('certificate', '\d+');
            Route::get('certificate-cancel-reasons', [Web\CertificateController::class, 'cancelReasons']);
            Route::get('certificate-settings', [Web\CertificateSettingsController::class, 'show']);
            Route::put('certificate-settings', [Web\CertificateSettingsController::class, 'update']);
            Route::post('certificates/{certificate:id}/issue', [Web\CertificateController::class, 'issue'])->where('certificate', '\d+');
            Route::post('certificates/{certificate:id}/reject', [Web\CertificateController::class, 'reject'])->where('certificate', '\d+');
        });

        // The signed-in user's own dashboard (figures scoped to their role).
        Route::get('my/dashboard', Web\RoleDashboardController::class);

        // The signed-in user's own notification inbox.
        Route::get('notifications', [Web\NotificationController::class, 'index']);
        Route::post('notifications/read-all', [Web\NotificationController::class, 'readAll']);
        Route::post('notifications/{notification:id}/read', [Web\NotificationController::class, 'read'])->where('notification', '\d+');

        // Notification Management (staff): the rules behind automatic notifications, and messages written by hand.
        Route::middleware('role:super_admin,admin,ob')->group(function () {
            Route::get('notification-rules', [Web\NotificationRuleController::class, 'index']);
            Route::put('notification-rules/{event}', [Web\NotificationRuleController::class, 'update']);
            Route::post('notification-rules/{event}/reset', [Web\NotificationRuleController::class, 'reset']);
            Route::post('notifications/send', [Web\NotificationRuleController::class, 'send']);
        });

        // Student learning: own courses, lessons and completion.
        Route::middleware('role:student')->prefix('my')->group(function () {
            // My certificates: issued ones to view and download, the rest with their status.
            Route::get('certificates', [Web\CertificateController::class, 'mine']);
            Route::get('certificate-qr', [Web\CertificateController::class, 'qrCodes']);

            // Quizzes: list, one quiz with my attempts, start / resume, submit, review.
            Route::get('assessments', [Web\MyAssessmentController::class, 'index']);
            Route::get('assessments/{assessment}', [Web\MyAssessmentController::class, 'show'])->where('assessment', '\d+');
            Route::post('assessments/{assessment}/start', [Web\MyAssessmentController::class, 'start'])->where('assessment', '\d+');
            Route::post('assessments/{assessment}/submit', [Web\MyAssessmentController::class, 'submit'])->where('assessment', '\d+');
            Route::post('assessments/{assessment}/submissions', [Web\MyAssessmentController::class, 'submitAssignment'])->where('assessment', '\d+');
            Route::get('assessments/{assessment}/submissions', [Web\MyAssessmentController::class, 'assignmentSubmissions'])->where('assessment', '\d+');
            Route::get('assessments/{assessment}/review', [Web\MyAssessmentController::class, 'review'])->where('assessment', '\d+');

            Route::get('courses', [Web\MyLearningController::class, 'index']);
            Route::get('courses/{course:id}', [Web\MyLearningController::class, 'show'])->where('course', '\d+');
            Route::post('lessons/{lesson:id}/open', [Web\MyLearningController::class, 'open'])->where('lesson', '\d+');
            Route::post('lessons/{lesson:id}/complete', [Web\MyLearningController::class, 'complete'])->where('lesson', '\d+');
        });

        // The Specialist pick-list of the user form
        Route::get('specialists', [Web\SpecialistController::class, 'index'])->middleware('role:super_admin,admin,ob');

        // Users (public_id contains slashes)
        Route::get('users', [Web\UserController::class, 'index']);
        Route::post('users', [Web\UserController::class, 'store']);
        Route::prefix('users/{user:public_id}')->where(['user' => '.+'])->group(function () {
            Route::post('suspend', [Web\UserController::class, 'suspend']);
            Route::post('activate', [Web\UserController::class, 'activate']);
            Route::post('reset-password', [Web\UserController::class, 'resetPassword']);
        });
        Route::get('users/{user:public_id}', [Web\UserController::class, 'show'])->where('user', '.+');
        Route::put('users/{user:public_id}', [Web\UserController::class, 'update'])->where('user', '.+');
        Route::delete('users/{user:public_id}', [Web\UserController::class, 'destroy'])->where('user', '.+');

        // Attendance
        Route::get('attendance', [Web\AttendanceController::class, 'index']);
        Route::get('attendance/list', [Web\AttendanceController::class, 'list'])->middleware('role:super_admin,admin,ob,mentor');
        Route::post('attendance', [Web\AttendanceController::class, 'store'])->middleware('role:super_admin,admin,ob,mentor');
        Route::post('attendance/bulk', [Web\AttendanceController::class, 'bulk'])->middleware('role:super_admin,admin,ob,mentor');

        // Attendance reports: a student reports Present / Absent for today, staff and mentors mark the final status.
        Route::get('attendance-requests', [Web\AttendanceRequestController::class, 'index'])->middleware('role:student,mentor,super_admin,admin,ob');
        Route::post('attendance-requests', [Web\AttendanceRequestController::class, 'store'])->middleware('role:student');
        Route::post('attendance-requests/{attendanceRequest:id}/mark', [Web\AttendanceRequestController::class, 'mark'])
            ->where('attendanceRequest', '\d+')->middleware('role:super_admin,admin,ob,mentor');

        // Common Floor
        Route::get('common-floor', [Web\CommonFloorController::class, 'index']);
        Route::post('common-floor', [Web\CommonFloorController::class, 'store']);

        // SHARP Community: groups, their people and messages. Reading and posting are checked per group (CommunityAccess);
        // creating a group is for staff, members and institutions; the review screens and removing a message are staff only.
        Route::prefix('community')->group(function () {
            Route::get('groups', [Web\CommunityController::class, 'index']);
            Route::get('groups/{group}', [Web\CommunityController::class, 'show'])->where('group', '\d+');
            Route::get('groups/{group}/messages', [Web\CommunityController::class, 'messages'])->where('group', '\d+');
            Route::post('groups/{group}/messages', [Web\CommunityController::class, 'post'])->where('group', '\d+');
            Route::post('groups/{group}/messages/{message}/pin', [Web\CommunityController::class, 'pinMessage'])->where(['group' => '\d+', 'message' => '\d+']);
            Route::post('groups/{group}/read', [Web\CommunityController::class, 'read'])->where('group', '\d+');
            Route::post('groups/{group}/pin', [Web\CommunityController::class, 'pin'])->where('group', '\d+');
            Route::post('dm', [Web\CommunityController::class, 'openDm']);
            Route::get('dms', [Web\CommunityController::class, 'dms']);

            Route::post('groups', [Web\CommunityController::class, 'store'])->middleware('role:super_admin,admin,ob,member,institution');
            // Changing a group: its creator or staff (checked in the service).
            Route::put('groups/{group}', [Web\CommunityController::class, 'update'])->where('group', '\d+');
            Route::post('groups/{group}/members', [Web\CommunityController::class, 'addMembers'])->where('group', '\d+');
            Route::post('groups/{group}/members/remove', [Web\CommunityController::class, 'removeMember'])->where('group', '\d+');
            Route::delete('groups/{group}', [Web\CommunityController::class, 'destroy'])->where('group', '\d+');

            Route::middleware('role:super_admin,admin,ob')->group(function () {
                Route::get('overview', [Web\CommunityController::class, 'overview']);
                Route::get('person', [Web\CommunityController::class, 'person']);
                Route::delete('groups/{group}/messages/{message}', [Web\CommunityController::class, 'removeMessage'])->where(['group' => '\d+', 'message' => '\d+']);
            });
        });

        // Staff only
        Route::middleware('role:super_admin,admin,ob')->group(function () {
            Route::post('common-floor/{message}/{action}', [Web\CommonFloorController::class, 'moderate']);
            Route::get('audit-logs', [Web\AuditLogController::class, 'index']);
            Route::get('dashboard', Web\DashboardController::class);
            Route::get('dashboard/activity', Web\ActivityReportController::class);
            Route::get('dashboard/programme', Web\ProgrammeOverviewController::class);
            Route::get('dashboard/analytics', Web\AnalyticsController::class);
        });
    });
});
