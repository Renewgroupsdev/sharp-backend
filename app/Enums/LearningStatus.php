<?php

namespace App\Enums;

enum LearningStatus: string
{
    case NotStarted = 'Not started';
    case InProgress = 'In progress';
    case Completed = 'Completed';

    public static function fromProgress(int $progress): self
    {
        return match (true) {
            $progress >= 100 => self::Completed,
            $progress > 0 => self::InProgress,
            default => self::NotStarted,
        };
    }

    /** Inclusive percentage range of the status, for filtering. */
    public function range(): array
    {
        return match ($this) {
            self::NotStarted => [0, 0],
            self::InProgress => [1, 99],
            self::Completed => [100, 100],
        };
    }
}
