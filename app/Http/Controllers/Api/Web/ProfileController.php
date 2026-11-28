<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\UserResource;
use App\Services\AuditLogger;
use App\Support\Avatar;
use App\Support\AvatarStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Edit your own profile, whatever your role: name, picture, phone, email, short description and biography. Username, role,
 * institute, batch and status stay with the administrators.
 */
class ProfileController extends ApiController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'mobile' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'short_description' => ['nullable', 'string', 'max:255'],
            'biography' => ['nullable', 'string', 'max:5000'],
            'avatar' => ['nullable', 'string', 'max:450000'], // a data URL of a new picture
            'avatar_remove' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($user, $data) {
            $user->update([
                'name' => trim($data['name']),
                'mobile' => $data['mobile'] ?? null,
                'email' => $data['email'] ?? null,
                'short_description' => $data['short_description'] ?? null,
                'biography' => $data['biography'] ?? null,
                'avatar_initials' => Avatar::initials($data['name']),
            ]);
            if (! empty($data['avatar'])) {
                $user->update(['avatar_url' => AvatarStore::save($user, $data['avatar'])]);
            } elseif (! empty($data['avatar_remove'])) {
                AvatarStore::delete($user->avatar_url);
                $user->update(['avatar_url' => null]);
            }
        });
        $this->audit->record($user, 'Updated own profile', $user->public_id);

        return $this->ok(['user' => new UserResource($user->refresh()->load('batch'))]);
    }
}
