<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A community group. The counts, last message, title and `can_post` are set by CommunityGroupService::decorate().
 *
 * @mixin \App\Models\CommunityGroup
 */
class CommunityGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->getAttribute('title'),
            'name' => $this->name,
            'kind' => $this->kind,
            'source' => $this->source,
            'course_id' => $this->course_id,
            'created_by' => $this->creator?->name,
            'members_total' => $this->getAttribute('members_total'),
            'messages_total' => $this->getAttribute('messages_total'),
            'last_message' => $this->getAttribute('last_message'),
            'can_post' => (bool) $this->getAttribute('can_post'),
            // Per person: pinned to the top of their chat list, and how many messages they have not read.
            'pinned' => $this->getAttribute('pinned_at') !== null,
            'pinned_at' => $this->getAttribute('pinned_at'),
            'unread' => (int) $this->getAttribute('unread'),
            // The two people of a personal chat.
            'participants' => $this->isDm() ? $this->members->map(fn ($u) => ['user_id' => $u->public_id, 'name' => $u->name])->values() : null,
            'created_at' => $this->created_at,
        ];
    }
}
