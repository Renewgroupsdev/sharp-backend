<?php

namespace App\Http\Controllers\Api\Web;

use App\Enums\Role;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\StudentScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The activity log. Super Admin, Admin and OB see everything. Everyone else sees their own activity (what they did and what
 * was done to their account); a mentor adds their assigned students, an institution the students of its college.
 * With `?user=<public id>` the list narrows to that one person, who must be inside the caller's scope.
 */
class AuditLogController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $publicId = (string) $request->query('user', '');
        $person = $publicId !== '' ? User::where('public_id', $publicId)->first() : null;
        $ids = $this->visibleUserIds($actor);

        if ($publicId !== '') {
            abort_unless($person, 404, 'User not found.');
            abort_unless($ids === null || $ids->clone()->where('id', $person->id)->exists(), 403, 'You can only view the activity of yourself and your own students.');
        }

        $entries = AuditLog::with('actor:id,name,role')
            ->when($person, fn ($q) => $q->where(fn ($w) => $w->where('target', $person->public_id)->orWhere('actor_id', $person->id)))
            ->when(! $person && $ids !== null, fn ($q) => $q->where(fn ($w) => $w
                ->whereIn('actor_id', $ids->clone()->select('id'))
                ->orWhereIn('target', $ids->clone()->select('public_id'))))
            ->latest('created_at')->latest('id')->limit($person ? 100 : 300)->get();

        return $this->ok(['entries' => AuditLogResource::collection($entries)]);
    }

    /** The people whose activity the caller may read, or null for no limit. */
    private function visibleUserIds(User $actor): ?Builder
    {
        if (in_array($actor->role, Role::staff(), true)) {
            return null;
        }
        $students = StudentScope::studentIds($actor); // a mentor's or an institution's students; null for other roles

        return User::query()->where(fn ($w) => $w
            ->where('id', $actor->id)
            ->when($students, fn ($q) => $q->orWhereIn('id', $students)));
    }
}
