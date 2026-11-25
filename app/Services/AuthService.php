<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Verify credentials and issue a Sanctum token.
     *
     * @param  list<Role>|null  $allowedRoles  null = any role
     * @return array{0: User, 1: string} user and plain-text token
     */
    public function login(string $username, string $password, string $device, ?array $allowedRoles = null, ?string $roleError = null): array
    {
        $user = User::where('username', $username)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            $this->audit->record(null, 'Failed sign-in attempt', $username);
            throw new AuthenticationException('Incorrect username or password.');
        }
        if ($user->isSuspended()) {
            throw new AuthorizationException('This account has been suspended. Contact your Admin.');
        }
        // Checked before a token exists, so a rejected role never holds a credential.
        if ($allowedRoles !== null && ! $user->hasRole(...$allowedRoles)) {
            throw new AuthorizationException($roleError ?? 'You do not have permission to do that.');
        }

        $user->forceFill(['last_login' => now()])->save();
        $this->audit->record($user, 'Signed in', null, $device);

        return [$user, $user->createToken($device)->plainTextToken];
    }
}
