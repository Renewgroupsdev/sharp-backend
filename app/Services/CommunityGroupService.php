<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Models\CommonFloorMessage;
use App\Models\Course;
use App\Models\CommunityGroup;
use App\Models\CommunityMessage;
use App\Models\User;
use App\Enums\Role;
use App\Support\StudentScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Groups: the two org-wide ones, a chat and an announcement group per course (created as soon as a batch takes the
 * course), custom groups staff create, and personal chats between two people. See CommunityAccess for who belongs.
 */
class CommunityGroupService
{
    /** A person counts as "signed in now" when they logged in within this many minutes. */
    private const ONLINE_MINUTES = 5;

    public function __construct(private readonly CommunityAccess $access, private readonly AuditLogger $audit, private readonly CommunityRealtime $realtime, private readonly NotificationService $notifications, private readonly CommunityChatStateService $states) {}

    /** Creates the Common Floor, the All users announcements and a chat + announcement group for every course a batch takes. */
    public function ensureSystemGroups(): void
    {
        foreach ([[CommunityGroup::CHAT, CommunityGroup::FLOOR_NAME], [CommunityGroup::ANNOUNCEMENT, CommunityGroup::ALL_USERS_NAME]] as [$kind, $name]) {
            CommunityGroup::firstOrCreate(['source' => CommunityGroup::FLOOR, 'kind' => $kind], ['name' => $name]);
        }

        $courses = Course::query()->whereIn('id', DB::table('batch_course')->select('course_id'))->get(['id', 'title']);
        $have = CommunityGroup::query()->where('source', CommunityGroup::COURSE)->get()->groupBy('course_id');
        foreach ($courses as $course) {
            foreach (CommunityGroup::KINDS as $kind) {
                $group = $have->get($course->id)?->firstWhere('kind', $kind);
                if (! $group) {
                    CommunityGroup::create(['source' => CommunityGroup::COURSE, 'kind' => $kind, 'course_id' => $course->id, 'name' => $course->title]);
                } elseif ($group->name !== $course->title) {
                    $group->update(['name' => $course->title]); // the course was renamed
                }
            }
        }
    }

    /**
     * Staff see every group; anyone else sees their own. Personal chats are listed separately (see `dms`).
     *
     * @param  array{kind?: string, source?: string, q?: string}  $filters
     * @return Collection<int, CommunityGroup>
     */
    public function list(User $actor, array $filters = []): Collection
    {
        $this->ensureSystemGroups();

        $groups = ($this->access->isStaff($actor) ? CommunityGroup::query() : $this->access->visibleTo($actor))
            ->where('source', '<>', CommunityGroup::DM)
            ->when(! empty($filters['kind']), fn (Builder $q) => $q->where('kind', $filters['kind']))
            ->when(! empty($filters['source']), fn (Builder $q) => $q->where('source', $filters['source']))
            ->when(! empty($filters['q']), fn (Builder $q) => $q->where('name', 'ilike', "%{$filters['q']}%"))
            ->with('creator:id,name')
            ->orderByRaw("CASE source WHEN 'floor' THEN 0 WHEN 'course' THEN 1 ELSE 2 END")->orderBy('name')
            ->get();

        return $this->decorate($actor, $groups);
    }

    /** A person's own personal chats; Super Admin, Admin and OB see every personal chat (read-only for the ones they are not in). */
    public function dms(User $actor): Collection
    {
        // Personal chats are switched off for everyone.
        return $this->decorate($actor, new Collection);

        $dms = ($this->access->isStaff($actor) ? CommunityGroup::query() : $this->access->visibleTo($actor))
            ->where('source', CommunityGroup::DM)
            ->when($actor->role === Role::Mentor, fn (Builder $q) => $q->orWhere(fn (Builder $o) => $o->where('source', CommunityGroup::DM)
                ->whereHas('members', fn (Builder $m) => $m->where('users.role', Role::Student->value)->whereIn('users.batch_id', StudentScope::batchIds($actor)))))
            ->when($actor->role === Role::Institution, fn (Builder $q) => $q->orWhere(fn (Builder $o) => $o->where('source', CommunityGroup::DM)
                ->whereHas('members', fn (Builder $m) => $m->where('users.role', Role::Student->value)->where('users.college_id', $actor->college_id ?? 0))))->with(['members:id,public_id,name', 'creator:id,name'])->latest('id')->get();

        return $this->decorate($actor, $dms);
    }

    public function find(User $actor, CommunityGroup $group): CommunityGroup
    {
        abort_unless($this->access->canView($actor, $group), 403, 'You are not a member of this group.');

        return $this->decorate($actor, collect([$group->load(['creator:id,name', 'members:id,public_id,name'])]))->first();
    }

    /**
     * The people in a group for the member list. The org-wide groups hold everyone, so they list the first 500 by name
     * (the full count is members_total).
     *
     * @return Collection<int, array{user: User, removable: bool, online: bool}>
     */
    public function memberList(CommunityGroup $group): Collection
    {
        $byHand = $group->members()->pluck('users.id')->all();
        $staff = CommunityAccess::staffRoles();

        return $this->access->members($group)->orderBy('name')->when($group->isFloor(), fn ($q) => $q->limit(500))->get(['id', 'public_id', 'name', 'role', 'org', 'avatar_initials', 'avatar_color', 'last_login', 'last_seen_at'])
            ->map(fn (User $u) => [
                'user' => $u,
                // Only people added by hand can be taken out; enrolment and staff membership are automatic.
                'removable' => ! $group->isDm() && in_array($u->id, $byHand, true) && ! in_array($u->role->value, $staff, true),
                'online' => $this->online($u),
            ]);
    }

    /** @param  list<string>  $memberPublicIds */
    public function create(User $actor, string $name, string $kind, array $memberPublicIds): CommunityGroup
    {
        $added = collect();
        $group = DB::transaction(function () use ($actor, $name, $kind, $memberPublicIds, &$added) {
            $group = CommunityGroup::create(['name' => $name, 'kind' => $kind, 'source' => CommunityGroup::CUSTOM, 'created_by' => $actor->id]);
            $added = $this->attach($group, $actor, $memberPublicIds);

            return $group;
        });
        $this->tellAdded($group, $actor, $added);

        $this->audit->record($actor, 'Created a community group', (string) $group->id, "{$name} · {$kind} · ".$group->members()->count().' selected');

        return $this->find($actor, $group);
    }

    /** Rename a custom group and/or replace the people in it. The creator or staff only. */
    public function update(User $actor, CommunityGroup $group, ?string $name, ?array $memberPublicIds): CommunityGroup
    {
        $this->assertManages($actor, $group);
        if ($group->source !== CommunityGroup::CUSTOM) {
            throw ValidationException::withMessages(['group' => 'Only custom groups can be edited. A course group follows the batches that take the course.']);
        }

        $before = $group->members()->pluck('users.id');
        $added = collect();
        DB::transaction(function () use ($group, $actor, $name, $memberPublicIds, &$added) {
            if ($name !== null) {
                $group->update(['name' => $name]);
            }
            if ($memberPublicIds !== null) {
                $group->members()->detach();
                $added = $this->attach($group, $actor, $memberPublicIds);
            }
        });
        $this->tellAdded($group, $actor, $added->diff($before)); // only people who were not in the group before

        $this->audit->record($actor, 'Updated a community group', (string) $group->id, (string) $group->name);

        return $this->find($actor, $group->refresh());
    }

    /** @param  list<string>  $memberPublicIds */
    public function addMembers(User $actor, CommunityGroup $group, array $memberPublicIds): CommunityGroup
    {
        $this->assertManages($actor, $group);
        $this->assertHasPeople($group);

        $added = $this->attach($group, $actor, $memberPublicIds);
        $this->tellAdded($group, $actor, $added);
        $this->audit->record($actor, 'Added community members', (string) $group->id, "{$group->name} · {$added->count()} added");

        return $this->find($actor, $group->refresh());
    }

    public function removeMember(User $actor, CommunityGroup $group, string $memberPublicId): CommunityGroup
    {
        $this->assertManages($actor, $group);
        $this->assertHasPeople($group);

        $person = User::query()->where('public_id', $memberPublicId)->firstOrFail();
        if (in_array($person->role->value, CommunityAccess::staffRoles(), true)) {
            throw ValidationException::withMessages(['member' => 'Admin, Super Admin and OB are in every group and cannot be removed.']);
        }
        if (! $group->members()->whereKey($person->id)->exists()) {
            throw ValidationException::withMessages(['member' => "{$person->name} is in this group through enrolment, so they cannot be removed by hand."]);
        }

        $group->members()->detach($person->id);
        $this->realtime->chatsChanged([$person->id], $group);
        $this->audit->record($actor, 'Removed a community member', (string) $group->id, "{$group->name} · {$person->name}");

        return $this->find($actor, $group->refresh());
    }

    /** Custom groups and personal chats can be deleted. The org-wide groups cannot, and course groups follow their course. */
    public function delete(User $actor, CommunityGroup $group): void
    {
        $this->assertManages($actor, $group);
        if (in_array($group->source, [CommunityGroup::FLOOR, CommunityGroup::COURSE], true)) {
            throw ValidationException::withMessages(['group' => 'The Common Floor, All users and course groups cannot be deleted.']);
        }

        $title = $this->titleOf($group);
        $group->delete();
        $this->audit->record($actor, 'Deleted a community group', (string) $group->id, $title);
    }

    /** One personal chat per pair of people; an existing one is reused. */
    public function openDm(User $actor, string $otherPublicId): CommunityGroup
    {
        throw ValidationException::withMessages(['user' => 'Personal messages are turned off.']);

        $other = User::query()->where('public_id', $otherPublicId)->where('status', '<>', UserStatus::Suspended->value)->firstOrFail();
        if ($other->id === $actor->id) {
            throw ValidationException::withMessages(['user' => 'Choose someone else to message.']);
        }

        $existing = CommunityGroup::query()->where('source', CommunityGroup::DM)
            ->whereHas('members', fn (Builder $m) => $m->whereKey($actor->id))
            ->whereHas('members', fn (Builder $m) => $m->whereKey($other->id))
            ->has('members', '=', 2)->first();
        if ($existing) {
            return $this->find($actor, $existing);
        }

        $group = DB::transaction(function () use ($actor, $other) {
            $group = CommunityGroup::create(['kind' => CommunityGroup::CHAT, 'source' => CommunityGroup::DM, 'created_by' => $actor->id]);
            $group->members()->attach([$actor->id => ['added_by' => $actor->id, 'created_at' => now()], $other->id => ['added_by' => $actor->id, 'created_at' => now()]]);

            return $group;
        });
        $this->realtime->chatsChanged([$actor->id, $other->id], $group);
        $this->audit->record($actor, 'Started a personal chat', (string) $group->id, "{$actor->name} ↔ {$other->name}");

        return $this->find($actor, $group);
    }

    /** Group counts and who is signed in now, for the cards on the Community page. */
    public function overview(): array
    {
        $this->ensureSystemGroups();
        $counts = CommunityGroup::query()->where('source', '<>', CommunityGroup::DM)->selectRaw('kind, source, count(*) as n')->groupBy('kind', 'source')->get();
        $n = fn (string $kind, ?string $source = null) => (int) $counts->where('kind', $kind)->when($source, fn ($c) => $c->where('source', $source))->sum('n');
        $active = User::query()->where('status', '<>', UserStatus::Suspended->value);

        return [
            'chat' => ['groups' => $n('Chat'), 'course' => $n('Chat', 'course'), 'custom' => $n('Chat', 'custom')],
            'announcement' => ['groups' => $n('Announcement'), 'course' => $n('Announcement', 'course'), 'custom' => $n('Announcement', 'custom')],
            'personal_chats' => CommunityGroup::query()->where('source', CommunityGroup::DM)->count(),
            'users_total' => (clone $active)->count(),
            'users_online' => (clone $active)->whereRaw('COALESCE(last_seen_at, last_login) >= ?', [now()->subMinutes(self::ONLINE_MINUTES)])->count(),
            'online_window_minutes' => self::ONLINE_MINUTES,
        ];
    }

    /** One person for staff to review: who they are, the groups they are in, and what they have written. */
    public function person(string $publicId): array
    {
        $person = User::query()->where('public_id', $publicId)->firstOrFail(['id', 'public_id', 'name', 'role', 'org', 'status', 'avatar_initials', 'avatar_color', 'last_login', 'last_seen_at']);
        $groups = $this->access->visibleTo($person)->where('source', '<>', CommunityGroup::DM)->orderBy('name')->get(['id', 'name', 'kind', 'source']);
        $staff = in_array($person->role->value, CommunityAccess::staffRoles(), true);

        return [
            'user' => $person,
            'online' => $this->online($person),
            // Staff are in every group, so list them all rather than only the ones they were added to.
            'groups' => $staff ? CommunityGroup::query()->where('source', '<>', CommunityGroup::DM)->orderBy('name')->get(['id', 'name', 'kind', 'source']) : $groups,
        ];
    }

    public function online(User $user): bool
    {
        $seen = $user->last_seen_at ?? $user->last_login;

        return $seen !== null && $seen->greaterThanOrEqualTo(now()->subMinutes(self::ONLINE_MINUTES));
    }

    /** A personal chat is titled by its two people; anything else by its name. */
    public function titleOf(CommunityGroup $group): string
    {
        if (! $group->isDm()) {
            return (string) $group->name;
        }

        return $group->members->isEmpty() ? 'Personal chat' : $group->members->pluck('name')->implode(' ↔ ');
    }

    /**
     * Sets, on each group: `members_total`, `messages_total`, `last_message` and `can_post` for the viewer, using a few
     * grouped queries instead of one per group.
     *
     * @param  Collection<int, CommunityGroup>  $groups
     * @return Collection<int, CommunityGroup>
     */
    private function decorate(User $viewer, Collection $groups): Collection
    {
        if ($groups->isEmpty()) {
            return $groups;
        }

        $ids = $groups->pluck('id');
        $counts = CommunityMessage::visible()->whereIn('group_id', $ids)->selectRaw('group_id, count(*) as n')->groupBy('group_id')->pluck('n', 'group_id');
        $latest = CommunityMessage::visible()->whereIn('group_id', $ids)->selectRaw('distinct on (group_id) group_id, user_id, created_at')->orderBy('group_id')->orderByDesc('id')->get()->keyBy('group_id');
        $names = User::query()->whereIn('id', $latest->pluck('user_id')->unique())->pluck('name', 'id');

        $states = $this->states->statesFor($viewer, $ids);
        $unread = $this->states->unreadCounts($viewer, $groups);

        $floorId = $groups->first(fn (CommunityGroup $g) => $g->usesFloorMessages())?->id;
        $floorLatest = $floorId ? CommonFloorMessage::visible()->with('author:id,name')->latest('created_at')->latest('id')->first() : null;
        $floorCount = $floorId ? CommonFloorMessage::visible()->count() : 0;

        foreach ($groups as $group) {
            $isFloorChat = $group->id === $floorId;
            $last = $isFloorChat ? $floorLatest : $latest->get($group->id);
            $group->setAttribute('members_total', $group->isDm() ? $group->members->count() : $this->access->members($group)->count());
            $group->setAttribute('messages_total', $isFloorChat ? $floorCount : (int) ($counts[$group->id] ?? 0));
            $group->setAttribute('last_message', $last ? [
                'at' => $last->created_at,
                'by' => $isFloorChat ? $last->author?->name : ($names[$last->user_id] ?? null),
            ] : null);
            $group->setAttribute('can_post', $this->access->canPost($viewer, $group));
            $group->setAttribute('title', $this->titleOf($group));
            $group->setAttribute('pinned_at', $states->get($group->id)?->pinned_at);
            $group->setAttribute('unread', $unread[$group->id] ?? 0);
        }

        return $groups;
    }

    /** The chat list and the bell of people who were just added to a group. */
    private function tellAdded(CommunityGroup $group, User $actor, Collection $userIds): void
    {
        $this->realtime->chatsChanged($userIds, $group);
        $this->notifications->notify('community_added', $userIds->values()->all(), [
            'group' => $group->name, 'kind' => strtolower($group->kind), 'actor' => $actor->name,
        ], ['url' => '/app/community', 'group_id' => $group->id]);
    }

    /**
     * Adds people by public id (active users only; staff are already in every group). Returns the ids of the people who were new;
     * the caller tells them (see tellAdded).
     *
     * @return Collection<int, int>
     */
    private function attach(CommunityGroup $group, User $actor, array $publicIds): Collection
    {
        $people = User::query()->whereIn('public_id', $publicIds)->where('status', '<>', UserStatus::Suspended->value)
            ->whereNotIn('role', CommunityAccess::staffRoles())->pluck('id');

        $already = $group->members()->pluck('users.id');
        $new = $people->diff($already);
        $group->members()->attach($new->mapWithKeys(fn ($id) => [$id => ['added_by' => $actor->id, 'created_at' => now()]])->all());

        return $new->values();
    }

    /** Staff, or the person who created the group, may manage it. */
    private function assertManages(User $actor, CommunityGroup $group): void
    {
        abort_unless($this->access->isStaff($actor) || $group->created_by === $actor->id, 403, 'You cannot change this group.');
    }

    private function assertHasPeople(CommunityGroup $group): void
    {
        if (in_array($group->source, [CommunityGroup::FLOOR, CommunityGroup::DM], true)) {
            throw ValidationException::withMessages(['group' => $group->isDm()
                ? 'A personal chat is between two people; members cannot be added or removed.'
                : 'Everyone is already in the Common Floor and All users groups.']);
        }
    }
}
