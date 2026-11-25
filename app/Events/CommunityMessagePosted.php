<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** A message was posted in a group. Sent at once (no queue worker needed) on the group's private channel. */
class CommunityMessagePosted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** @param  array<string, mixed>  $message  the same shape the messages endpoint returns */
    public function __construct(public int $groupId, public array $message) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("community.group.{$this->groupId}");
    }

    public function broadcastAs(): string
    {
        return 'message.posted';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['message' => $this->message];
    }
}
