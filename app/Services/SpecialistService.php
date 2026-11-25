<?php

namespace App\Services;

use App\Models\Specialist;

/** The Specialist pick-list. A user holds several, stored as one comma-separated text ("Startup, Leadership"). */
class SpecialistService
{
    /** @return list<string> */
    public function names(): array
    {
        return Specialist::query()->orderBy('name')->pluck('name')->all();
    }

    /**
     * Cleans a user's specialist text: trimmed, no commas inside a name, each name once (ignoring case), joined with ", ".
     * Returns null when nothing is left.
     */
    public static function normalize(mixed $text): ?string
    {
        if (! is_string($text)) {
            return null;
        }
        $seen = [];
        foreach (explode(',', $text) as $part) {
            $part = trim((string) preg_replace('/\s+/', ' ', $part));
            if ($part !== '' && ! isset($seen[mb_strtolower($part)])) {
                $seen[mb_strtolower($part)] = mb_substr($part, 0, 80);
            }
        }

        return $seen ? implode(', ', $seen) : null;
    }

    /** Adds any names of this text that the list does not have yet, so they are offered next time. */
    public function remember(?string $text): void
    {
        if ($text === null || $text === '') {
            return;
        }
        $known = array_map('mb_strtolower', $this->names());
        foreach (explode(', ', $text) as $name) {
            if (! in_array(mb_strtolower($name), $known, true)) {
                Specialist::create(['name' => $name]);
                $known[] = mb_strtolower($name);
            }
        }
    }
}
