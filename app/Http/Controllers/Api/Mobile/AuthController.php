<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Enums\Role;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends ApiController
{
    public function __construct(
        protected readonly AuthService $auth,
        protected readonly UserService $users,
    ) {}

    /** Token label; also recorded in the audit log. */
    protected function device(): string
    {
        return 'mobile';
    }

    /** @return list<Role>|null roles allowed to sign in on this channel (null = all) */
    protected function allowedRoles(): ?array
    {
        return null;
    }

    protected function roleError(): ?string
    {
        return null;
    }

    public function login(LoginRequest $request): JsonResponse
    {
        [$user, $token] = $this->auth->login(
            $request->validated('username'),
            $request->validated('password'),
            $this->device(),
            $this->allowedRoles(),
            $this->roleError(),
        );

        return $this->ok(['token' => $token, 'user' => new UserResource($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->ok(['message' => 'Signed out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return $this->ok(['user' => new UserResource($request->user())]);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $this->users->changePassword($request->user(), $request->validated('new_password'));

        return $this->ok(['message' => 'Password updated.']);
    }
}
