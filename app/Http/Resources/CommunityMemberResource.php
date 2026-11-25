<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One row of a group's member list: `['user' => User, 'removable' => bool, 'online' => bool]`. */
class CommunityMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $this->resource['user'];

        return [
            'user_id' => $user->public_id,
            'name' => $user->name,
            'role' => $user->role,
            'org' => $user->org,
            'initials' => $user->avatar_initials,
            'color' => $user->avatar_color,
            'online' => $this->resource['online'],
            'last_seen_at' => ($user->last_seen_at ?? $user->last_login)?->toISOString(),
            'removable' => $this->resource['removable'],
        ];
    }
}
