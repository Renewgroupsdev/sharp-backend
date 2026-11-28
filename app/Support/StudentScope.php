<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Whose students a signed-in user may see: a student only themselves, staff everyone, a mentor the students of the
 * batches that take a course the mentor teaches, and an institution the students of its own college.
 * One place for the rule, so every list, count and detail view agrees.
 */
final class StudentScope
{
    /** A mentor's courses: those that list the mentor by name. */
    public static function courseIds(User $mentor): \Illuminate\Database\Query\Builder
    {
        return DB::table('courses as c')
            ->whereRaw("(CASE WHEN jsonb_typeof(c.mentors::jsonb) = 'array' THEN c.mentors::jsonb ELSE '[]'::jsonb END) @> jsonb_build_array(?::text)", [$mentor->name])
            ->select('c.id');
    }

    /** A mentor's batches: those that take one of the mentor's courses. */
    public static function batchIds(User $mentor): \Illuminate\Database\Query\Builder
    {
        return DB::table('batch_course as bc')->whereIn('bc.course_id', self::courseIds($mentor))->select('bc.batch_id');
    }

    /** Whether a student batch belongs to the mentor. */
    public static function ownsBatch(User $mentor, ?int $batchId): bool
    {
        return $batchId !== null && self::batchIds($mentor)->where('bc.batch_id', $batchId)->exists();
    }

    /** The students of an institution's own college. */
    public static function collegeStudents(User $institution): Builder
    {
        return User::query()->where('role', Role::Student->value)->where('college_id', $institution->college_id ?? 0);
    }

    /** The batches that hold the students of an institution's college. */
    public static function collegeBatchIds(User $institution): \Illuminate\Database\Query\Builder
    {
        return DB::table('users as s')->where('s.role', Role::Student->value)->where('s.college_id', $institution->college_id ?? 0)->whereNotNull('s.batch_id')->select('s.batch_id');
    }

    /** The courses those batches take: what the institution's students are enrolled in. */
    public static function collegeCourseIds(User $institution): \Illuminate\Database\Query\Builder
    {
        return DB::table('batch_course as bc')->whereIn('bc.batch_id', self::collegeBatchIds($institution))->select('bc.course_id');
    }

    /** The mentors teaching those courses, by name (courses list their mentors by name). */
    public static function collegeMentorNames(User $institution): array
    {
        return DB::table('courses')->whereIn('id', self::collegeCourseIds($institution))->pluck('mentors')
            ->flatMap(fn ($json) => (array) json_decode((string) $json, true))->filter()->unique()->values()->all();
    }

    /** Ids of the students a mentor or an institution may see (as a sub-query), or null when there is no such limit. */
    public static function studentIds(User $actor): ?Builder
    {
        return match ($actor->role) {
            Role::Mentor => User::query()->whereIn('batch_id', self::batchIds($actor))->select('id'),
            Role::Institution => self::collegeStudents($actor)->select('id'),
            default => null,
        };
    }

    /** Limits a query on a student-owned table to the students $actor may see. */
    public static function apply(Builder $query, User $actor, string $studentColumn): Builder
    {
        if ($actor->role === Role::Student) {
            return $query->where($studentColumn, $actor->id);
        }
        $ids = self::studentIds($actor);

        return $ids ? $query->whereIn($studentColumn, $ids) : $query;
    }

    /** Whether $actor may open records that belong to $student. */
    public static function allows(User $actor, User $student): bool
    {
        return match ($actor->role) {
            Role::Student => $actor->id === $student->id,
            Role::Mentor => self::ownsBatch($actor, $student->batch_id),
            Role::Institution => $student->college_id !== null && $student->college_id === $actor->college_id,
            default => true,
        };
    }
}
