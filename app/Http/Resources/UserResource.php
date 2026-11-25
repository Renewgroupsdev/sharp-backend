<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'username' => $this->username,
            'role' => $this->role,
            'college_id' => $this->college_id,
            'org' => $this->org,
            'batch_id' => $this->batch_id,
            'batch' => $this->batch?->name ?? 'All batches',
            'mobile' => $this->mobile,
            'email' => $this->email,
            'status' => $this->status,
            'specialist' => $this->specialist,
            'short_description' => $this->short_description,
            'biography' => $this->biography,
            'must_change_password' => $this->must_change_password,
            'initials' => $this->avatar_initials,
            'color' => $this->avatar_color,
            'avatar' => \App\Support\AvatarStore::url($this->avatar_url),
            'last_login' => $this->last_login,
            'created_at' => $this->created_at,
        ];
    }
}
