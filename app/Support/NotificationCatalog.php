<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Every event the app can notify about, with who gets it and the default wording. Staff change the on/off state and the
 * wording in Notification Management (notification_rules); the events themselves are fired from the services.
 * `{name}` style placeholders in `title` / `body` are filled from the variables the service passes (`vars`).
 */
final class NotificationCatalog
{
    public const EVENTS = [
        'certificate_issued' => [
            'label' => 'Certificate issued', 'audience' => 'Student', 'type' => 'certificate',
            'title' => 'Your certificate is ready',
            'body' => 'Your certificate for {course} has been issued (no. {number}).',
            'vars' => ['course', 'number'],
        ],
        'certificate_cancelled' => [
            'label' => 'Certificate cancelled', 'audience' => 'Student', 'type' => 'certificate',
            'title' => 'Your certificate was cancelled',
            'body' => 'Your certificate for {course} (no. {number}) has been cancelled. Reason: {reason}. Contact the programme office.',
            'vars' => ['course', 'number', 'reason'],
        ],
        'course_added_student' => [
            'label' => 'Course added to a batch', 'audience' => 'Student', 'type' => 'enrollment',
            'title' => 'New course added',
            'body' => '{course} now runs for {batch}.{when}',
            'vars' => ['course', 'batch', 'when'],
        ],
        'course_added_mentor' => [
            'label' => 'Course added to a mentor\'s batch', 'audience' => 'Mentor', 'type' => 'enrollment',
            'title' => 'You are teaching a new course',
            'body' => '{course} was added to {batch}.{when}',
            'vars' => ['course', 'batch', 'when'],
        ],
        'course_removed' => [
            'label' => 'Course removed from a batch', 'audience' => 'Student, Mentor', 'type' => 'enrollment',
            'title' => 'Course removed',
            'body' => '{course} was removed from {batch}.',
            'vars' => ['course', 'batch'],
        ],
        'module_unlocked' => [
            'label' => 'Module unlocked early', 'audience' => 'Student', 'type' => 'learning',
            'title' => 'A module was unlocked for you',
            'body' => '“{module}” in {course} is now open.',
            'vars' => ['module', 'course'],
        ],
        'assignment_graded' => [
            'label' => 'Assignment graded', 'audience' => 'Student', 'type' => 'assessment',
            'title' => 'Your {assessment} was {outcome}',
            'body' => '{assessment}: {marks}/{max} marks.{feedback}',
            'vars' => ['assessment', 'outcome', 'marks', 'max', 'feedback'],
        ],
        'attendance_reported' => [
            'label' => 'Student sent an attendance report', 'audience' => 'Super Admin, Admin, OB, batch Mentor', 'type' => 'attendance',
            'title' => '{student} reported {status}',
            'body' => '{student} ({batch}) reported {status} for {date}.{reason}',
            'vars' => ['student', 'batch', 'status', 'date', 'reason'],
        ],
        'batch_ending' => [
            'label' => 'Batch end date is near', 'audience' => 'Super Admin, Admin, OB', 'type' => 'batch',
            'title' => '{batch} {when}',
            'body' => 'Batch {batch} ends on {date}. Review attendance, certificates and results before it closes.',
            'vars' => ['batch', 'when', 'date'],
        ],
        'attendance_pending' => [
            'label' => 'Students with attendance still pending today', 'audience' => 'Super Admin, Admin, OB, batch Mentor', 'type' => 'attendance',
            'title' => '{count} attendance pending',
            'body' => '{count} student(s) {scope} have no attendance for {date}. Mark them from Attendance.',
            'vars' => ['count', 'scope', 'date'],
        ],
        'attendance_manual' => [
            'label' => 'Attendance marked by hand', 'audience' => 'Student', 'type' => 'attendance',
            'title' => 'Attendance marked {status}',
            'body' => 'Your attendance for {date} was marked {status} by {marker}.{reason}',
            'vars' => ['date', 'status', 'marker', 'reason'],
        ],
        'certificate_requested' => [
            'label' => 'Student requested a certificate', 'audience' => 'Super Admin, Admin, OB, batch Mentor', 'type' => 'certificate',
            'title' => '{student} requested a certificate',
            'body' => '{student} ({batch}) asked for the {course} certificate.',
            'vars' => ['student', 'batch', 'course'],
        ],
        'certificate_recommended' => [
            'label' => 'Mentor reviewed a certificate', 'audience' => 'Super Admin, Admin, OB', 'type' => 'certificate',
            'title' => 'Certificate {decision} by {mentor}',
            'body' => '{mentor} {decision} the {course} certificate of {student}.{note}',
            'vars' => ['mentor', 'decision', 'student', 'course', 'note'],
        ],
        'certificate_rejected' => [
            'label' => 'Certificate rejected', 'audience' => 'Student', 'type' => 'certificate',
            'title' => 'Your certificate request was not approved',
            'body' => 'Your request for the {course} certificate was rejected. Contact the programme office.',
            'vars' => ['course'],
        ],
        'assignment_submitted' => [
            'label' => 'Student submitted an assignment', 'audience' => 'Super Admin, Admin, OB, batch Mentor', 'type' => 'assessment',
            'title' => '{student} submitted {assessment}',
            'body' => '{student} ({batch}) submitted {assessment} (version {version}).',
            'vars' => ['student', 'batch', 'assessment', 'version'],
        ],
        'submission_review_started' => [
            'label' => 'Assignment review started', 'audience' => 'Student', 'type' => 'assessment',
            'title' => 'Your {assessment} is being reviewed',
            'body' => '{reviewer} started reviewing your submission.',
            'vars' => ['assessment', 'reviewer'],
        ],
        'quiz_scored' => [
            'label' => 'Quiz submitted', 'audience' => 'Student', 'type' => 'assessment',
            'title' => 'Your {quiz} was submitted',
            'body' => '{result}',
            'vars' => ['quiz', 'result'],
        ],
        'assessment_published' => [
            'label' => 'New assignment, quiz or assessment published', 'audience' => 'Students of the course', 'type' => 'assessment',
            'title' => 'New {kind}: {title}',
            'body' => '{title} ({course}) is now available.{due}',
            'vars' => ['kind', 'title', 'course', 'due'],
        ],
        'user_created' => [
            'label' => 'New user created', 'audience' => 'Super Admin, Admin, OB', 'type' => 'account',
            'title' => 'New user: {name}',
            'body' => '{name} ({role}) was added by {actor}.',
            'vars' => ['name', 'role', 'actor'],
        ],
        'password_reset' => [
            'label' => 'Password reset by the office', 'audience' => 'The person whose password was reset', 'type' => 'account',
            'title' => 'Your password was reset',
            'body' => '{actor} reset your password. Sign in with the temporary password you were given and choose a new one.',
            'vars' => ['actor'],
        ],
        'community_added' => [
            'label' => 'Added to a community group', 'audience' => 'The people added', 'type' => 'community',
            'title' => 'You were added to {group}',
            'body' => '{actor} added you to the {kind} group {group}.',
            'vars' => ['group', 'kind', 'actor'],
        ],
        'chat_mention' => [
            'label' => 'Mentioned in a chat', 'audience' => 'The person mentioned with @', 'type' => 'community',
            'title' => '{author} mentioned you in {group}',
            'body' => '{message}',
            'vars' => ['group', 'author', 'message'],
        ],
        'announcement_posted' => [
            'label' => 'Announcement posted', 'audience' => 'Members of the announcement group', 'type' => 'community',
            'title' => '{group}: new announcement',
            'body' => '{author}: {message}',
            'vars' => ['group', 'author', 'message'],
        ],
        'attendance_marked' => [
            'label' => 'Attendance report marked', 'audience' => 'Student', 'type' => 'attendance',
            'title' => 'Attendance marked {status}',
            'body' => 'Your attendance report for {date} was marked {status} by {marker}.',
            'vars' => ['date', 'status', 'marker'],
        ],
    ];

    /** @return array<string, mixed> */
    public static function get(string $event): array
    {
        return self::EVENTS[$event] ?? throw new InvalidArgumentException("Unknown notification event [{$event}].");
    }

    /** Fills `{placeholders}` from `$vars`; a placeholder with no value becomes empty. */
    public static function render(string $template, array $vars): string
    {
        return trim((string) preg_replace_callback('/\{(\w+)\}/', fn (array $m) => (string) ($vars[$m[1]] ?? ''), $template));
    }
}
