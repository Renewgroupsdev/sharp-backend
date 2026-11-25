<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whose students a signed-in user may see: a student only themselves, a mentor their own batch, staff everyone.
 * One place for the rule, so every list, count and detail view agrees.
 */
final class StudentScope
{
    /** Limits a query on a student-owned table to the students $actor may see. */
    public static function apply(Builder $query, User $actor, string $studentColumn): Builder
    {
        return match ($actor->role) {
            Role::Student => $query->where($studentColumn, $actor->id),
            Role::Mentor => $query->whereIn($studentColumn, User::query()->where('batch_id', $actor->batch_id ?? 0)->select('id')),
            default => $query,
        };
    }

    /** Whether $actor may open records that belong to $student. */
    public static function allows(User $actor, User $student): bool
    {
        return match ($actor->role) {
            Role::Student => $actor->id === $student->id,
            Role::Mentor => $student->batch_id !== null && $student->batch_id === $actor->batch_id,
            default => true,
        };
    }
}
