<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Str;

class AuditLogger
{
    public function record(?User $actor, string $action, ?string $target = null, ?string $details = null): AuditLog
    {
        return AuditLog::create([
            'actor_id' => $actor?->getKey(),
            'action' => Str::limit($action, 250, ''),
            'target' => $target !== null ? Str::limit($target, 100, '') : null,
            'details' => $details,
        ]);
    }
}
