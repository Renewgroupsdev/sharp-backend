<?php

namespace App\Services;

use App\Enums\ModerationAction;
use App\Models\CommonFloorMessage;
use App\Models\User;
use Illuminate\Support\Collection;

class CommonFloorService
{
    private const AUTHOR_COLUMNS = 'author:id,public_id,name,role,avatar_initials,avatar_color';

    public function __construct(private readonly AuditLogger $audit, private readonly CommunityRealtime $realtime) {}

    /** Latest messages, oldest first, for chat-style rendering. */
    public function recent(int $limit = 100): Collection
    {
        return CommonFloorMessage::visible()
            ->with(self::AUTHOR_COLUMNS)
            ->latest('created_at')->latest('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }

    public function post(User $author, string $message): CommonFloorMessage
    {
        $row = CommonFloorMessage::create(['user_id' => $author->id, 'message' => $message])
            ->refresh()
            ->load(self::AUTHOR_COLUMNS);
        $this->realtime->floorPosted($row);

        return $row;
    }

    public function moderate(User $actor, CommonFloorMessage $message, ModerationAction $action): void
    {
        $message->update(match ($action) {
            ModerationAction::Flag => ['flagged' => true],
            ModerationAction::Unflag => ['flagged' => false],
            ModerationAction::Mute => ['muted' => true],
        });

        if ($action === ModerationAction::Mute) {
            $this->realtime->floorRemoved($message);
        }
        $this->audit->record($actor, $action->auditLabel(), (string) $message->id);
    }
}
