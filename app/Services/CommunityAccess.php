<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\CommunityGroup;
use App\Models\User;
use App\Support\StudentScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who belongs to a group, who may read it and who may post. One place, so the group list, the chat and the member
 * screens can never disagree.
 *
 *  - Common Floor and All users: every active user.
 *  - Course group: students, mentors and trainers of the batches that take the course, plus anyone added by hand.
 *  - Custom group: the people added by hand.
 *  - Staff roles (super admin, admin, OB) are in every group except personal chats.
 *  - Personal chat: its two people only.
 * Staff can read (but not write in) a personal chat they are not part of.
 */
class CommunityAccess
{
    /** Roles that sit in every group and can read every conversation. */
    public static function staffRoles(): array
    {
        return array_map(fn (Role $r) => $r->value, Role::staff());
    }

    /** Roles that learn or teach in a batch, and so belong to the course groups of that batch. */
    private const BATCH_ROLES = ['student', 'mentor', 'trainer'];

    public function isStaff(User $user): bool
    {
        return in_array($user->role, Role::staff(), true);
    }

    /** Active users who belong to the group. */
    public function members(CommunityGroup $group): Builder
    {
        $active = User::query()->where('status', '<>', UserStatus::Suspended->value);
        $byHand = DB::table('community_group_members')->where('group_id', $group->id)->select('user_id');

        return match ($group->source) {
            CommunityGroup::FLOOR => $active,
            CommunityGroup::COURSE => $active->where(fn (Builder $w) => $w
                ->where(fn (Builder $s) => $s->whereIn('role', self::BATCH_ROLES)
                    ->whereIn('batch_id', DB::table('batch_course')->where('course_id', $group->course_id)->select('batch_id')))
                ->orWhereIn('id', $byHand)
                ->orWhereIn('role', self::staffRoles())),
            CommunityGroup::CUSTOM => $active->where(fn (Builder $w) => $w->whereIn('id', $byHand)->orWhereIn('role', self::staffRoles())),
            default => $active->whereIn('id', $byHand), // personal chat
        };
    }

    public function isMember(User $user, CommunityGroup $group): bool
    {
        if ($user->status === UserStatus::Suspended) {
            return false;
        }

        return $this->members($group)->whereKey($user->id)->exists();
    }

    /** Whether the person is one of the two people of a personal chat. */
    public function takesPart(User $user, CommunityGroup $group): bool
    {
        return $group->members()->whereKey($user->id)->exists();
    }

    public function canView(User $user, CommunityGroup $group): bool
    {
        if ($group->isDm()) {
            return $this->takesPart($user, $group) || $this->isStaff($user) || $this->mentorOverseesDm($user, $group); // two people write in it; staff and the assigned mentor can read it
        }
        if ($this->isStaff($user)) {
            return true; // staff read every group
        }
        if ($group->created_by === $user->id) {
            return true; // whoever created a group can always open it
        }

        return $this->isMember($user, $group);
    }

    /** A mentor (their assigned students) or an institution (its college's students) may read those students' personal chats (read-only). */
    public function mentorOverseesDm(User $user, CommunityGroup $group): bool
    {
        if (! in_array($user->role, [Role::Mentor, Role::Institution], true) || ! $group->isDm()) {
            return false;
        }
        $ids = $group->members()->pluck('users.id');
        $students = User::query()->whereIn('id', $ids)->where('role', Role::Student->value);

        return $user->role === Role::Mentor
            ? $students->whereIn('batch_id', StudentScope::batchIds($user))->exists()
            : $students->where('college_id', $user->college_id ?? 0)->exists();
    }

    /** Pinning a message in a chat: anyone who can open the chat. A pin is personal: only the person who pinned it sees it. */
    public function canPin(User $user, CommunityGroup $group): bool
    {
        return $this->canView($user, $group);
    }

    /** Announcement groups: only staff and the person who created the group may post. A personal chat: only its two people. */
    public function canPost(User $user, CommunityGroup $group): bool
    {
        if ($group->isDm()) {
            return $this->takesPart($user, $group);
        }
        if ($group->isAnnouncement()) {
            return $this->isStaff($user) || $group->created_by === $user->id;
        }

        return $this->canView($user, $group);
    }

    /**
     * The groups a person sees in their own chat list: the org-wide ones, the course groups of their batch, and anything
     * they were added to by hand (custom groups, extras, personal chats). Staff use the full list instead.
     */
    public function visibleTo(User $user): Builder
    {
        $handAdded = DB::table('community_group_members')->where('user_id', $user->id)->select('group_id');
        $courses = DB::table('batch_course')->where('batch_id', $user->batch_id ?? 0)->select('course_id')
            ->union(DB::table('student_course')->where('user_id', $user->id)->select('course_id'));
        $inBatch = in_array($user->role->value, self::BATCH_ROLES, true);

        // Personal (one-to-one) chats are switched off: they never appear in anyone's list.
        return CommunityGroup::query()->where('source', '<>', CommunityGroup::DM)->where(fn (Builder $w) => $w
            ->where('source', CommunityGroup::FLOOR)
            ->orWhereIn('id', $handAdded)
            ->orWhere(fn (Builder $own) => $own->where('created_by', $user->id)->where('source', '<>', CommunityGroup::DM))
            ->when($inBatch, fn (Builder $q) => $q->orWhere(fn (Builder $c) => $c->where('source', CommunityGroup::COURSE)->whereIn('course_id', $courses))));
    }
}
