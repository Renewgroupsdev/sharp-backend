<?php

namespace App\Enums;

enum Role: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Ob = 'ob';
    case Mentor = 'mentor';
    case Trainer = 'trainer';
    case Student = 'student';
    case Member = 'member';
    case Institution = 'institution';

    /** Roles that run the programme day to day. */
    public static function staff(): array
    {
        return [self::SuperAdmin, self::Admin, self::Ob];
    }

    public static function attendanceMarkers(): array
    {
        return [self::SuperAdmin, self::Admin, self::Ob, self::Mentor];
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Prefix used when generating public IDs (students use their own format). */
    public function idPrefix(): string
    {
        return match ($this) {
            self::SuperAdmin => 'SA',
            self::Admin => 'AD',
            self::Ob => 'OB',
            self::Mentor => 'MN',
            self::Trainer => 'TR',
            self::Member => 'MB',
            self::Student => 'SH',
            self::Institution => 'IN',
        };
    }

    public function requiresCollege(): bool
    {
        return in_array($this, [self::Student, self::Institution], true);
    }

    public function defaultOrg(?string $collegeName = null): ?string
    {
        return match ($this) {
            self::Trainer => 'TNCCI Faculty Panel',
            self::Ob, self::Admin, self::SuperAdmin => 'TNCCI Programme Office',
            self::Member => 'SHARP network member',
            self::Institution, self::Student => $collegeName,
            default => null,
        };
    }

    public function isIn(self ...$roles): bool
    {
        return in_array($this, $roles, true);
    }
}
