<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\CommonFloorMessage */
class CommonFloorMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'message' => $this->message,
            'flagged' => $this->flagged,
            'muted' => $this->muted,
            'created_at' => $this->created_at,
            'author_name' => $this->author?->name,
            'author_role' => $this->author?->role,
            'author_public_id' => $this->author?->public_id,
            'avatar_initials' => $this->author?->avatar_initials,
            'avatar_color' => $this->author?->avatar_color,
        ];
    }
}
