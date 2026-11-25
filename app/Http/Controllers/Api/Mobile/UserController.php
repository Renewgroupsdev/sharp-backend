<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\ApiController;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends ApiController
{
    public function __construct(protected readonly UserService $users) {}

    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->with('batch:id,name')
            ->filter($request->only(['role', 'college_id', 'status', 'q']))
            ->latest('id')
            ->limit(500)
            ->get();

        return $this->ok(['users' => UserResource::collection($users), 'count' => $users->count()]);
    }

    public function show(User $user): JsonResponse
    {
        return $this->ok(['user' => new UserResource($user)]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        [$user, $tempPassword] = $this->users->create($request->user(), $request->role(), $request->validated());

        return $this->ok([
            'user' => new UserResource($user),
            'temp_password' => $tempPassword, // shown once, at creation only
        ], 201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->authorize('manage', $user);

        return $this->ok(['user' => new UserResource($this->users->update($request->user(), $user, $request->validated()))]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorize('manage', $user);
        $this->users->delete($request->user(), $user);

        return $this->ok(['message' => "{$user->name} has been deleted."]);
    }

    public function suspend(Request $request, User $user): JsonResponse
    {
        $this->authorize('manage', $user);
        $this->users->suspend($request->user(), $user);

        return $this->ok(['message' => "{$user->name} has been suspended."]);
    }

    public function activate(Request $request, User $user): JsonResponse
    {
        $this->authorize('manage', $user);
        $this->users->activate($request->user(), $user);

        return $this->ok(['message' => "{$user->name} has been reactivated."]);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $this->authorize('manage', $user);
        $temp = $this->users->resetPassword($request->user(), $user);

        return $this->ok(['message' => "Temporary password reset for {$user->name}.", 'temp_password' => $temp]);
    }
}
