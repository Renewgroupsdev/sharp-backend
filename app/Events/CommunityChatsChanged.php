<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The chat list of some people changed (a personal chat started, they were added to or removed from a group).
 * Public ids hold characters a channel name cannot (SH2026/THC/0413), so the channel is named by the id in hex.
 */
class CommunityChatsChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** @param  list<string>  $userIds  public ids of the people */
    public function __construct(public array $userIds, public ?int $groupId = null) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(fn (string $id) => new PrivateChannel('community.user.'.bin2hex($id)), $this->userIds);
    }

    public function broadcastAs(): string
    {
        return 'community.changed';
    }

    /** @return array<string, int|null> */
    public function broadcastWith(): array
    {
        return ['group_id' => $this->groupId];
    }
}
