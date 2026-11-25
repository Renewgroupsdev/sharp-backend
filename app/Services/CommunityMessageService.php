<?php

namespace App\Services;

use App\Models\CommonFloorMessage;
use App\Models\CommunityGroup;
use App\Models\CommunityMessage;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Messages of a group. The Common Floor chat is stored in common_floor_messages (the mobile app writes there too); every
 * other group uses community_messages. Both come out in one shape:
 * `['id', 'group_id', 'body', 'at', 'author' => User|null]`.
 */
class CommunityMessageService
{
    private const AUTHOR = 'author:id,public_id,name,role,avatar_initials,avatar_color';

    public function __construct(private readonly CommunityAccess $access, private readonly AuditLogger $audit, private readonly CommunityRealtime $realtime, private readonly NotificationService $notifications) {}

    /** The latest messages of the group, oldest first. The viewer must be able to read the group. */
    public function list(User $viewer, CommunityGroup $group, int $limit = 200): Collection
    {
        abort_unless($this->access->canView($viewer, $group), 403, 'You are not a member of this group.');

        $rows = $group->usesFloorMessages()
            ? CommonFloorMessage::visible()->with(self::AUTHOR)->latest('created_at')->latest('id')->limit($limit)->get()
            : $group->messages()->visible()->with(self::AUTHOR)->latest('id')->limit($limit)->get();

        return $rows->reverse()->values()->map(fn ($m) => $this->shape($group, $m));
    }

    public const MAX_PINS = 10;

    /** The messages this person pinned in this chat, most recently pinned first. Messages that were removed drop out. */
    public function pinned(User $viewer, CommunityGroup $group): Collection
    {
        abort_unless($this->access->canView($viewer, $group), 403, 'You are not a member of this group.');

        $ids = DB::table('community_pinned_messages')->where(['group_id' => $group->id, 'user_id' => $viewer->id])->orderByDesc('created_at')->pluck('message_id');
        if ($ids->isEmpty()) {
            return collect();
        }
        $rows = ($group->usesFloorMessages()
            ? CommonFloorMessage::visible()->with(self::AUTHOR)->whereIn('id', $ids)->get()
            : $group->messages()->visible()->with(self::AUTHOR)->whereIn('id', $ids)->get())->keyBy('id');

        return $ids->filter(fn ($id) => $rows->has($id))->map(fn ($id) => $this->shape($group, $rows[$id]))->values();
    }

    /** Pins or unpins one message of the chat for this person only. */
    public function pin(User $actor, CommunityGroup $group, int $messageId, bool $pinned): Collection
    {
        abort_unless($this->access->canView($actor, $group), 403, 'You are not a member of this group.');
        abort_unless($this->access->canPin($actor, $group), 403, 'You cannot pin messages in this chat.');

        $exists = $group->usesFloorMessages()
            ? CommonFloorMessage::visible()->whereKey($messageId)->exists()
            : $group->messages()->visible()->whereKey($messageId)->exists();
        abort_unless($exists, 404, 'Message not found.');

        if ($pinned) {
            $count = DB::table('community_pinned_messages')->where(['group_id' => $group->id, 'user_id' => $actor->id])->where('message_id', '<>', $messageId)->count();
            if ($count >= self::MAX_PINS) {
                throw ValidationException::withMessages(['pinned' => 'A chat can have up to '.self::MAX_PINS.' pinned messages. Unpin one first.']);
            }
            DB::statement(
                'INSERT INTO community_pinned_messages (group_id, message_id, user_id, created_at) VALUES (?, ?, ?, clock_timestamp())
                 ON CONFLICT (group_id, message_id, user_id) DO UPDATE SET created_at = clock_timestamp()',
                [$group->id, $messageId, $actor->id],
            );
        } else {
            DB::table('community_pinned_messages')->where(['group_id' => $group->id, 'message_id' => $messageId, 'user_id' => $actor->id])->delete();
        }

        return $this->pinned($actor, $group);
    }

    public function post(User $author, CommunityGroup $group, string $body): array
    {
        abort_unless($this->access->canView($author, $group), 403, 'You are not a member of this group.');
        if (! $this->access->canPost($author, $group)) {
            throw ValidationException::withMessages(['body' => $this->whyNot($group)]);
        }

        $message = $group->usesFloorMessages()
            ? CommonFloorMessage::create(['user_id' => $author->id, 'message' => $body])
            : $group->messages()->create(['user_id' => $author->id, 'body' => $body]);

        $shaped = $this->shape($group, $message->refresh()->load(self::AUTHOR));
        $this->realtime->posted($group, $shaped);
        if ($group->isAnnouncement()) {
            $this->notifications->notify('announcement_posted', $this->access->members($group)->where('users.id', '<>', $author->id)->pluck('users.id')->all(), [
                'group' => $this->title($group), 'author' => $author->name, 'message' => Str::limit($body, 140),
            ], ['url' => '/app/community', 'group_id' => $group->id]);
        }

        return $shaped;
    }

    /** Staff take a message out of the conversation. It is hidden, not deleted, and the removal is logged. */
    public function remove(User $actor, CommunityGroup $group, int $messageId): void
    {
        abort_unless($this->access->isStaff($actor) && ! $group->isDm(), 403, 'Only staff can remove messages, and not from personal chats.');

        if ($group->usesFloorMessages()) {
            $message = CommonFloorMessage::visible()->findOrFail($messageId);
            $message->update(['muted' => true]);
            $by = $message->author?->name;
        } else {
            $message = $group->messages()->visible()->findOrFail($messageId);
            $message->update(['removed_by' => $actor->id, 'removed_at' => now()]);
            $by = $message->author?->name;
        }

        DB::table('community_pinned_messages')->where(['group_id' => $group->id, 'message_id' => $messageId])->delete(); // everyone's pins of it
        $this->realtime->removed($group, $messageId);
        $this->audit->record($actor, 'Removed a community message', (string) $group->id, "{$this->title($group)} · message by {$by}");
    }

    /** Everything a person has written, newest first, with the group each message is in (for the "conversations by person" view). */
    public function byUser(User $person, int $limit = 100): Collection
    {
        $groups = CommunityGroup::query()->where('source', '<>', CommunityGroup::DM)->get()->keyBy('id'); // personal chats stay private
        $floor = $groups->firstWhere(fn (CommunityGroup $g) => $g->usesFloorMessages());

        $own = CommunityMessage::visible()->where('user_id', $person->id)->whereIn('group_id', $groups->keys())->latest('id')->limit($limit)->get()
            ->map(fn (CommunityMessage $m) => $this->shape($groups->get($m->group_id), $m, $person));
        $onFloor = $floor
            ? CommonFloorMessage::visible()->where('user_id', $person->id)->latest('id')->limit($limit)->get()->map(fn ($m) => $this->shape($floor, $m, $person))
            : collect();

        return $own->concat($onFloor)->sortByDesc('at')->take($limit)->values();
    }

    /** The shape the API returns, whichever table the message came from. */
    private function shape(CommunityGroup $group, CommonFloorMessage|CommunityMessage $m, ?User $author = null): array
    {
        return [
            'id' => $m->id,
            'group_id' => $group->id,
            'group' => $this->title($group),
            'body' => $m instanceof CommonFloorMessage ? $m->message : $m->body,
            'at' => $m->created_at,
            'author' => $author ?? $m->author,
        ];
    }

    private function title(CommunityGroup $group): string
    {
        return $group->isDm() ? 'Personal chat' : (string) $group->name;
    }

    private function whyNot(CommunityGroup $group): string
    {
        return match (true) {
            $group->isDm() => 'Only the two people in this conversation can write in it.',
            $group->isAnnouncement() => 'Only staff and the group\'s creator can post announcements here.',
            default => 'You are not a member of this group.',
        };
    }
}
