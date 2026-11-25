<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * New inbox items for some people, sent the moment they are stored so open screens show them at once. One event reaches
 * all the recipients (one private channel each, named by the public id in hex because ids hold characters a channel
 * name cannot).
 */
class NotificationsPushed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  list<string>  $publicIds  recipients
     * @param  array{type: string, title: string, body: string, url: string}  $payload
     */
    public function __construct(public array $publicIds, public array $payload) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(fn (string $id) => new PrivateChannel(self::channelFor($id)), $this->publicIds);
    }

    public static function channelFor(string $publicId): string
    {
        return 'notifications.'.bin2hex($publicId);
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    /** @return array<string, string> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
