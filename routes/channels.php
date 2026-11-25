<?php

use App\Models\CommunityGroup;
use App\Services\CommunityAccess;
use Illuminate\Support\Facades\Broadcast;

/*
| Private WebSocket channels. A person is let into a channel only if the API would let them read the same thing.
*/

// A person's own channel: tells their open screens that their chat list changed (a new personal chat, added to a group).
Broadcast::channel('community.user.{key}', function ($user, $key) {
    return bin2hex((string) $user->public_id) === (string) $key;
});

// A person's own notifications, pushed the moment they are stored (the same hex-of-public-id naming).
Broadcast::channel('notifications.{key}', function ($user, $key) {
    return bin2hex((string) $user->public_id) === (string) $key;
});

// One group's live messages: exactly the people who may read the group over the API.
Broadcast::channel('community.group.{id}', function ($user, $id) {
    $group = CommunityGroup::find($id);

    return $group !== null && app(CommunityAccess::class)->canView($user, $group);
});
