<?php

namespace App\Support;

use Illuminate\Support\Str;

final class Avatar
{
    /** Two initials from a full name, e.g. "Priya R" -> "PR". */
    public static function initials(string $name): string
    {
        $parts = array_values(array_filter(preg_split('/\s+/', trim($name))));

        return match (count($parts)) {
            0 => '??',
            1 => Str::upper(mb_substr($parts[0], 0, 2)),
            default => Str::upper(mb_substr($parts[0], 0, 1).mb_substr(end($parts), 0, 1)),
        };
    }

    /** Stable colour per seed so avatars look varied but never change. */
    public static function color(string $seed): string
    {
        $palette = config('sharp.avatar_palette');

        return $palette[crc32($seed) % count($palette)];
    }
}
