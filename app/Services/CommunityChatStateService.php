<?php

namespace App\Services;

use App\Models\CommonFloorMessage;
use App\Models\CommunityGroup;
use App\Models\CommunityMessage;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Per-person chat state: pinned chats (top of the list, like Telegram) and how far each chat has been read. */
class CommunityChatStateService
{
    public const MAX_PINS = 5;

    public function __construct(private readonly CommunityAccess $access) {}

    /** @return Collection<int, object> group id => state row */
    public function statesFor(User $user, Collection $groupIds): Collection
    {
        return DB::table('community_chat_states')->where('user_id', $user->id)->whereIn('group_id', $groupIds)->get()->keyBy('group_id');
    }

    /** The newest message the person has read in this chat (their own marker, else the chat's baseline). */
    public function lastRead(User $user, CommunityGroup $group): int
    {
        $marker = DB::table('community_chat_states')->where(['group_id' => $group->id, 'user_id' => $user->id])->value('last_read_message_id');

        return (int) ($marker ?? $group->read_baseline_id ?? 0);
    }

    /**
     * Unread messages per chat for this person: other people's visible messages after their read marker.
     *
     * @param  Collection<int, CommunityGroup>  $groups
     * @return array<int, int> group id => count (only chats with unread messages)
     */
    public function unreadCounts(User $user, Collection $groups): array
    {
        $out = [];
        $regular = $groups->reject(fn (CommunityGroup $g) => $g->usesFloorMessages())->pluck('id');
        if ($regular->isNotEmpty()) {
            $in = implode(',', array_map('intval', $regular->all()));
            $rows = DB::select(
                "SELECT m.group_id, COUNT(*) AS n FROM community_messages m
                 JOIN community_groups g ON g.id = m.group_id
                 LEFT JOIN community_chat_states s ON s.group_id = m.group_id AND s.user_id = ?
                 WHERE m.group_id IN ({$in}) AND m.removed_at IS NULL AND m.user_id <> ? AND m.id > COALESCE(s.last_read_message_id, g.read_baseline_id)
                 GROUP BY m.group_id",
                [$user->id, $user->id],
            );
            foreach ($rows as $r) {
                $out[(int) $r->group_id] = (int) $r->n;
            }
        }
        if ($floor = $groups->first(fn (CommunityGroup $g) => $g->usesFloorMessages())) {
            $n = CommonFloorMessage::visible()->where('user_id', '<>', $user->id)->where('id', '>', $this->lastRead($user, $floor))->count();
            if ($n > 0) {
                $out[$floor->id] = $n;
            }
        }

        return $out;
    }

    /** Marks the chat read up to a message (the newest one when none is given). Never moves the marker backwards. */
    public function markRead(User $user, CommunityGroup $group, ?int $upTo = null): int
    {
        abort_unless($this->access->canView($user, $group), 403, 'You are not a member of this group.');

        $latest = (int) ($group->usesFloorMessages()
            ? CommonFloorMessage::visible()->max('id')
            : CommunityMessage::visible()->where('group_id', $group->id)->max('id'));
        $id = min($upTo ?? $latest, $latest);

        DB::statement(
            'INSERT INTO community_chat_states (group_id, user_id, last_read_message_id, created_at, updated_at) VALUES (?, ?, ?, now(), now())
             ON CONFLICT (group_id, user_id) DO UPDATE SET last_read_message_id = GREATEST(COALESCE(community_chat_states.last_read_message_id, 0), EXCLUDED.last_read_message_id), updated_at = now()',
            [$group->id, $user->id, $id],
        );

        return $this->lastRead($user, $group);
    }

    /** Pins or unpins a chat for this person only. At most MAX_PINS chats can be pinned. */
    public function pin(User $user, CommunityGroup $group, bool $pinned): void
    {
        abort_unless($this->access->canView($user, $group), 403, 'You are not a member of this group.');

        if ($pinned) {
            $others = DB::table('community_chat_states')->where('user_id', $user->id)->whereNotNull('pinned_at')->where('group_id', '<>', $group->id)->count();
            if ($others >= self::MAX_PINS) {
                throw ValidationException::withMessages(['pinned' => 'You can pin up to '.self::MAX_PINS.' chats. Unpin one first.']);
            }
        }

        DB::statement(
            'INSERT INTO community_chat_states (group_id, user_id, pinned_at, created_at, updated_at) VALUES (?, ?, ?, now(), now())
             ON CONFLICT (group_id, user_id) DO UPDATE SET pinned_at = EXCLUDED.pinned_at, updated_at = now()',
            [$group->id, $user->id, $pinned ? now() : null],
        );
    }
}
