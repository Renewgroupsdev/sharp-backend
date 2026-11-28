<?php

namespace App\Http\Controllers\Api\Web;

use App\Support\StudentScope;
use App\Enums\Role;
use App\Http\Controllers\Api\Mobile\UserController as MobileUserController;
use App\Http\Resources\AttendanceResource;
use App\Http\Resources\UserResource;
use App\Enums\CourseStatus;
use App\Models\Course;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\AuditLogger;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends MobileUserController
{
    /** The user list. A mentor sees themselves and the students of their own batch only; everyone else as before. */
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $users = User::query()
            ->with('batch:id,name')
            ->filter($request->only(['role', 'college_id', 'status', 'q']))
            ->when($actor->role === Role::Mentor, fn ($q) => $q->where(fn ($w) => $w
                ->where('id', $actor->id)
                ->orWhere(fn ($s) => $s->where('role', Role::Student->value)->whereIn('batch_id', StudentScope::batchIds($actor)))
                ->orWhere(fn ($m) => $m->where('role', Role::Mentor->value)->whereIn('name', $this->coMentors($actor)))))
            ->when($actor->role === Role::Institution, fn ($q) => $q->where(fn ($w) => $w
                ->where('id', $actor->id)
                ->orWhere(fn ($s) => $s->where('role', Role::Student->value)->where('college_id', $actor->college_id ?? 0))
                ->orWhere(fn ($m) => $m->where('role', Role::Mentor->value)->whereIn('name', StudentScope::collegeMentorNames($actor)))))
            ->latest('id')
            ->limit(500)
            ->get();

        return $this->ok(['users' => UserResource::collection($users), 'count' => $users->count()]);
    }

    /** A student's courses: those from their batch, those given to them directly, and the live courses they could still be given. */
    public function courses(User $user): JsonResponse
    {
        abort_unless($user->role === Role::Student, 422, 'Only students are enrolled in courses.');
        $live = Course::query()->where('status', CourseStatus::Published->value)->orderBy('title')->get(['id', 'title']);
        $mine = Course::query()->forStudent($user)->pluck('courses.id')->all();
        $direct = DB::table('student_course')->where('user_id', $user->id)->pluck('course_id')->all();

        return $this->ok([
            'enrolled' => $live->filter(fn ($c) => in_array($c->id, $mine, true))->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'direct' => in_array($c->id, $direct, true)])->values(),
            'available' => $live->reject(fn ($c) => in_array($c->id, $mine, true))->map(fn ($c) => ['id' => $c->id, 'title' => $c->title])->values(),
        ]);
    }

    public function enroll(Request $request, User $user, AuditLogger $audit): JsonResponse
    {
        abort_unless($user->role === Role::Student, 422, 'Only students are enrolled in courses.');
        $data = $request->validate(['course_id' => ['required', 'integer', Rule::exists('courses', 'id')->whereNull('deleted_at')]]);
        DB::table('student_course')->insertOrIgnore(['user_id' => $user->id, 'course_id' => $data['course_id'], 'added_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $audit->record($request->user(), 'Enrolled '.$user->name.' in a course', $user->public_id, 'Course #'.$data['course_id']);

        return $this->courses($user);
    }

    /** Takes back a course given directly; courses that come with the batch are changed under Enrollment. */
    public function unenroll(Request $request, User $user, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['course_id' => ['required', 'integer']]);
        DB::table('student_course')->where(['user_id' => $user->id, 'course_id' => $data['course_id']])->delete();
        $audit->record($request->user(), 'Removed '.$user->name.' from a course', $user->public_id, 'Course #'.$data['course_id']);

        return $this->courses($user);
    }

    /** Names of the mentors on the courses this mentor teaches (themselves included). */
    private function coMentors(User $mentor): array
    {
        return DB::table('courses')->whereIn('id', StudentScope::courseIds($mentor))->pluck('mentors')
            ->flatMap(fn ($json) => (array) json_decode((string) $json, true))->filter()->unique()->values()->all();
    }

    /** User detail page: students come back with their attendance in the same call. A mentor opens only their own students. */
    public function show(User $user): JsonResponse
    {
        $actor = request()->user();
        abort_if($actor->role === Role::Mentor && $user->id !== $actor->id && ! ($user->role === Role::Student && StudentScope::allows($actor, $user)) && ! ($user->role === Role::Mentor && in_array($user->name, $this->coMentors($actor), true)), 403, 'You can only view your own students.');

        abort_if($actor->role === Role::Institution && $user->id !== $actor->id && ! ($user->role === Role::Student && StudentScope::allows($actor, $user)) && ! ($user->role === Role::Mentor && in_array($user->name, StudentScope::collegeMentorNames($actor), true)), 403, 'You can only view the students of your institute.');

        $data = ['user' => new UserResource($user)];

        if ($user->role === Role::Student) {
            $result = app(AttendanceService::class)->forStudent($user, 14);
            $data['attendance'] = [
                'rate' => $result['rate'],
                'records' => AttendanceResource::collection($result['records']),
            ];
        }

        return $this->ok($data);
    }
}
