<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A message as CommunityMessageService shapes it: `['id', 'group_id', 'group', 'body', 'at', 'author' => User|null]`. */
class CommunityMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $author = $this->resource['author'];

        return [
            'id' => $this->resource['id'],
            'group_id' => $this->resource['group_id'],
            'group' => $this->resource['group'],
            'body' => $this->resource['body'],
            'at' => $this->resource['at'],
            'author' => $author ? [
                'user_id' => $author->public_id,
                'name' => $author->name,
                'role' => $author->role,
                'initials' => $author->avatar_initials,
                'color' => $author->avatar_color,
            ] : null,
        ];
    }
}
