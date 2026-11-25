<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\AuditLog */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'actor_id' => $this->actor_id,
            'actor_name' => $this->actor?->name,
            'actor_role' => $this->whenLoaded('actor', fn () => $this->actor?->role),
            'action' => $this->action,
            'target' => $this->target,
            'details' => $this->details,
            'created_at' => $this->created_at,
        ];
    }
}
