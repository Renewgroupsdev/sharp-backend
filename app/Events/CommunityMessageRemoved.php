<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** Staff removed a message from a group; open chats drop it. */
class CommunityMessageRemoved implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $groupId, public int $messageId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("community.group.{$this->groupId}");
    }

    public function broadcastAs(): string
    {
        return 'message.removed';
    }

    /** @return array<string, int> */
    public function broadcastWith(): array
    {
        return ['message_id' => $this->messageId];
    }
}
