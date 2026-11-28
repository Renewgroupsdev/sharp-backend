<?php

namespace App\Http\Controllers\Api\Web;

use App\Enums\Role;
use App\Http\Controllers\Api\ApiController;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\StudentScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Mentor feedback on a student in a course. A student reads their own; staff read all; a mentor and an institution read the
 * feedback of the students they may see. Staff and mentors write it (a mentor only for their own students and courses).
 */
class CourseFeedbackController extends ApiController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $data = $request->validate(['course_id' => ['required', 'integer'], 'student_id' => ['nullable', 'string', 'max:60']]);

        $rows = DB::table('course_feedback as f')
            ->join('users as s', 's.id', '=', 'f.student_id')
            ->leftJoin('users as a', 'a.id', '=', 'f.author_id')
            ->where('f.course_id', $data['course_id'])
            ->when($actor->role === Role::Student, fn ($q) => $q->where('f.student_id', $actor->id))
            ->when($actor->role !== Role::Student && ($ids = StudentScope::studentIds($actor)), fn ($q) => $q->whereIn('f.student_id', $ids->select('id')))
            ->when(! empty($data['student_id']) && $actor->role !== Role::Student, fn ($q) => $q->where('s.public_id', $data['student_id']))
            ->orderByDesc('f.id')
            ->get(['f.id', 'f.course_id', 'f.lesson_id', 'f.text', 'f.created_at', 's.public_id as student_id', 'a.name as by']);

        return $this->ok(['feedback' => $rows->map(fn ($r) => [
            'id' => $r->id, 'course_id' => $r->course_id, 'student_id' => $r->student_id,
            'topic' => $r->lesson_id ? (string) $r->lesson_id : '', 'text' => $r->text, 'by' => $r->by ?? 'Mentor', 'created_at' => $r->created_at,
        ])->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        $data = $request->validate([
            'course_id' => ['required', 'integer', 'exists:courses,id'],
            'student_id' => ['required', 'string', 'max:60'],
            'lesson_id' => ['nullable', 'integer'],
            'text' => ['required', 'string', 'max:2000'],
        ]);
        $student = User::students()->where('public_id', $data['student_id'])->firstOrFail();
        abort_unless(StudentScope::allows($actor, $student), 403, 'You can only give feedback to your own students.');
        abort_if($actor->role === Role::Mentor && ! StudentScope::courseIds($actor)->where('c.id', $data['course_id'])->exists(), 403, 'You can only give feedback on the courses you teach.');

        $id = DB::table('course_feedback')->insertGetId([
            'course_id' => $data['course_id'], 'student_id' => $student->id, 'lesson_id' => $data['lesson_id'] ?? null,
            'author_id' => $actor->id, 'text' => trim($data['text']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record($actor, 'Gave course feedback', $student->public_id, 'Course #'.$data['course_id']);

        return $this->ok(['id' => $id], 201);
    }
}
