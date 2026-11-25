<?php

namespace App\Http\Controllers\Api\Web;

use App\Enums\Role;
use App\Http\Controllers\Api\Mobile\UserController as MobileUserController;
use App\Http\Resources\AttendanceResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;

class UserController extends MobileUserController
{
    /** User detail page: students come back with their attendance in the same call. */
    public function show(User $user): JsonResponse
    {
        $data = ['user' => new UserResource($user)];

        if ($user->role === Role::Student) {
            $result = app(AttendanceService::class)->forStudent($user, 14);
            $data['attendance'] = [
                'rate' => $result['rate'],
                'records' => AttendanceResource::collection($result['records']),
            ];
        }

        return $this->ok($data);
    }
}
