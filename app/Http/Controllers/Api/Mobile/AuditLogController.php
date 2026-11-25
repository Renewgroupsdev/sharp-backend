<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\ApiController;

use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends ApiController
{
    /** The latest entries; with `?user=<public id>` only what that person did, or what was done to them. */
    public function index(Request $request): JsonResponse
    {
        $publicId = (string) $request->query('user', '');
        $userId = $publicId !== '' ? User::where('public_id', $publicId)->value('id') : null;

        $entries = AuditLog::with('actor:id,name,role')
            ->when($publicId !== '', fn ($q) => $q->where(fn ($w) => $w->where('target', $publicId)->when($userId, fn ($x) => $x->orWhere('actor_id', $userId))))
            ->latest('created_at')->latest('id')->limit($publicId !== '' ? 100 : 300)->get();

        return $this->ok(['entries' => AuditLogResource::collection($entries)]);
    }
}
