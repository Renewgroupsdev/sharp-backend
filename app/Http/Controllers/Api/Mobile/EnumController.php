<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Enums\AttendanceStatus;
use App\Enums\CollegeStatus;
use App\Enums\ModerationAction;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/** Read-only lookup lists so clients never hard-code enum values. */
class EnumController extends ApiController
{
    /** @var array<string, class-string<\BackedEnum>> */
    private const ENUMS = [
        'roles' => Role::class,
        'user-statuses' => UserStatus::class,
        'college-statuses' => CollegeStatus::class,
        'attendance-statuses' => AttendanceStatus::class,
        'moderation-actions' => ModerationAction::class,
    ];
    // {enum} names are roles, user-statuses, college-statuses, attendance-statuses, moderation-actions
    public function index(): JsonResponse
    {
        return $this->ok(['enums' => array_map($this->options(...), self::ENUMS)]);
    }

    public function show(string $enum): JsonResponse
    {
        abort_unless(isset(self::ENUMS[$enum]), 404, 'Unknown enum.');

        return $this->ok(['options' => $this->options(self::ENUMS[$enum])]);
    }

    /** @param class-string<\BackedEnum> $enum */
    private function options(string $enum): array
    {
        return array_map(fn (\BackedEnum $case) => [
            'value' => $case->value,
            'label' => Str::headline($case->name),
        ], $enum::cases());
    }
}
