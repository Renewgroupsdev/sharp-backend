<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\College;
use App\Models\User;
use App\Support\Avatar;
use App\Support\AvatarStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserService
{
    public function __construct(
        private readonly PublicIdGenerator $ids,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {}

    /** @return array{0: User, 1: string} created user and the one-time temporary password */
    public function create(User $actor, Role $role, array $data): array
    {
        $college = $role->requiresCollege() ? College::findOrFail($data['college_id']) : null;
        $temp = $data['temp_password'] ?? $this->generateTempPassword();

        $user = DB::transaction(function () use ($actor, $role, $data, $college, $temp) {
            $publicId = $this->ids->next($role, $college?->code);

            $user = User::create([
                'public_id' => $publicId,
                'name' => $data['name'],
                'username' => $data['username'] ?? $this->suggestUsername($data['name']),
                'password' => $temp,
                'must_change_password' => true,
                'role' => $role,
                'college_id' => $college?->id,
                'org' => $data['org'] ?? $role->defaultOrg($college?->name),
                'batch_id' => $data['batch_id'] ?? null,
                'mobile' => $data['mobile'] ?? null,
                'email' => $data['email'] ?? null,
                'status' => UserStatus::Invited,
                'avatar_initials' => Avatar::initials($data['name']),
                'avatar_color' => Avatar::color($data['name'].$publicId),
                'created_by' => $actor->id,
            ]);
            if (! empty($data['avatar'])) {
                $user->update(['avatar_url' => AvatarStore::save($user, $data['avatar'])]);
            }

            $this->audit->record($actor, "Created user {$publicId} ({$role->value}) — username and temporary password issued", $publicId);

            return $user;
        });

        $this->notifications->notifyStaff('user_created', null, ['name' => $user->name, 'role' => $role->value, 'actor' => $actor->name], ['url' => '/app/users'], $actor->id);

        return [$user->refresh(), $temp];
    }

    /** Edit profile fields. Role and status are not editable here. */
    public function update(User $actor, User $target, array $data): User
    {
        $college = $target->role->requiresCollege() ? College::findOrFail($data['college_id']) : null;

        DB::transaction(function () use ($actor, $target, $data, $college) {
            app(SpecialistService::class)->remember($data['specialist'] ?? null);
            $target->update([
                'name' => $data['name'],
                'username' => $data['username'],
                'college_id' => $college?->id ?? $data['college_id'] ?? null,
                'org' => $data['org'] ?? ($college?->name ?? $target->org),
                'batch_id' => $data['batch_id'] ?? null,
                'mobile' => $data['mobile'] ?? null,
                'email' => $data['email'] ?? null,
                'specialist' => $data['specialist'] ?? null,
                'short_description' => $data['short_description'] ?? null,
                'biography' => $data['biography'] ?? null,
                'avatar_initials' => Avatar::initials($data['name']),
            ]);
            if (! empty($data['avatar'])) {
                $target->update(['avatar_url' => AvatarStore::save($target, $data['avatar'])]);
            } elseif (! empty($data['avatar_remove'])) {
                AvatarStore::delete($target->avatar_url);
                $target->update(['avatar_url' => null]);
            }
            $this->audit->record($actor, 'Updated user '.$target->public_id, $target->public_id);
        });

        return $target->refresh();
    }

    /** Hard delete. Refused for yourself and for accounts holding attendance records (they would be cascaded away). */
    public function delete(User $actor, User $target): void
    {
        if ($actor->is($target)) {
            throw ValidationException::withMessages(['user' => 'You cannot delete your own account.']);
        }
        if ($target->attendance()->exists()) {
            throw ValidationException::withMessages(['user' => "{$target->name} has attendance records. Suspend the account instead."]);
        }

        DB::transaction(function () use ($actor, $target) {
            $target->tokens()->delete();
            $target->delete();
            $this->audit->record($actor, 'Deleted user '.$target->public_id.' ('.$target->name.')', $target->public_id);
        });
    }

    public function suspend(User $actor, User $target): void
    {
        DB::transaction(function () use ($actor, $target) {
            $target->update(['status' => UserStatus::Suspended]);
            $target->tokens()->delete();
            $this->audit->record($actor, 'Suspended '.$target->name, $target->public_id);
        });
    }

    public function activate(User $actor, User $target): void
    {
        $target->update(['status' => UserStatus::Active]);
        $this->audit->record($actor, 'Reactivated '.$target->name, $target->public_id);
    }

    /** @return string the new temporary password */
    public function resetPassword(User $actor, User $target): string
    {
        $temp = $this->generateTempPassword();

        DB::transaction(function () use ($actor, $target, $temp) {
            $target->update(['password' => $temp, 'must_change_password' => true]);
            $target->tokens()->delete();
            $this->audit->record($actor, 'Reset password for '.$target->name, $target->public_id);
        });
        $this->notifications->notify('password_reset', $target->id, ['actor' => $actor->name]);

        return $temp;
    }

    public function changePassword(User $user, string $newPassword): void
    {
        DB::transaction(function () use ($user, $newPassword) {
            $user->update(['password' => $newPassword, 'must_change_password' => false]);
            // Keep only the session that made the change.
            $user->tokens()->where('id', '!=', $user->currentAccessToken()?->id)->delete();
            $this->audit->record($user, 'Created their own password', null, 'First sign-in or password change');
        });
    }

    /** Username like "priya.r", made unique with a numeric suffix. */
    public function suggestUsername(string $name): string
    {
        $clean = Str::lower(preg_replace('/[^a-z\s]/', '', Str::lower($name)));
        $parts = array_values(array_filter(preg_split('/\s+/', trim($clean))));
        $base = $parts ? implode('.', array_slice($parts, 0, 2)) : 'new.user';

        $taken = User::query()->where('username', 'like', $base.'%')->pluck('username')->flip();

        $username = $base;
        for ($n = 2; isset($taken[$username]); $n++) {
            $username = $base.$n;
        }

        return $username;
    }

    private function generateTempPassword(): string
    {
        return config('sharp.temp_password_prefix').substr(bin2hex(random_bytes(4)), 0, 6);
    }
}
