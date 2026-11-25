<?php

namespace App\Enums;

enum ModerationAction: string
{
    case Flag = 'flag';
    case Unflag = 'unflag';
    case Mute = 'mute';

    public function auditLabel(): string
    {
        return match ($this) {
            self::Flag => 'Flagged a Common Floor message',
            self::Unflag => 'Unflagged a Common Floor message',
            self::Mute => 'Removed a Common Floor message',
        };
    }
}
