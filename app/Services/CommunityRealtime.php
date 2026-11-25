<?php

namespace App\Services;

use App\Events\CommunityChatsChanged;
use App\Events\CommunityMessagePosted;
use App\Events\CommunityMessageRemoved;
use App\Http\Resources\CommunityMessageResource;
use App\Models\CommonFloorMessage;
use App\Models\CommunityGroup;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pushes chat changes to open screens over WebSockets (Laravel Reverb). Chat never depends on it: if the socket server is
 * down the message is already saved, the failure is logged, and the screens catch up through their normal refresh.
 */
class CommunityRealtime
{
    /** @param  array<string, mixed>  $message  as CommunityMessageService shapes it */
    public function posted(CommunityGroup $group, array $message): void
    {
        $payload = (new CommunityMessageResource($message))->resolve(request());
        $this->send(fn () => event(new CommunityMessagePosted($group->id, $payload)));
    }

    public function removed(CommunityGroup $group, int $messageId): void
    {
        $this->send(fn () => event(new CommunityMessageRemoved($group->id, $messageId)));
    }

    /** @param  iterable<int>  $userIds */
    public function chatsChanged(iterable $userIds, ?CommunityGroup $group = null): void
    {
        $ids = User::query()->whereIn('id', collect($userIds)->unique()->all())->pluck('public_id')->map(fn ($p) => (string) $p)->all();
        if ($ids) {
            $this->send(fn () => event(new CommunityChatsChanged($ids, $group?->id)));
        }
    }

    /** A message written to the Common Floor from the mobile app or the legacy endpoint reaches the same live chat. */
    public function floorPosted(CommonFloorMessage $message): void
    {
        $group = CommunityGroup::query()->where('source', CommunityGroup::FLOOR)->where('kind', CommunityGroup::CHAT)->first();
        if ($group) {
            $this->posted($group, [
                'id' => $message->id, 'group_id' => $group->id, 'group' => (string) $group->name,
                'body' => $message->message, 'at' => $message->created_at, 'author' => $message->author,
            ]);
        }
    }

    public function floorRemoved(CommonFloorMessage $message): void
    {
        $group = CommunityGroup::query()->where('source', CommunityGroup::FLOOR)->where('kind', CommunityGroup::CHAT)->first();
        if ($group) {
            $this->removed($group, $message->id);
        }
    }

    private function send(callable $dispatch): void
    {
        try {
            $dispatch();
        } catch (Throwable $e) {
            Log::warning('Community real-time push failed: '.$e->getMessage());
        }
    }
}
