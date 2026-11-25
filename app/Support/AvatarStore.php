<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Profile pictures: a PNG, JPEG or WebP of up to 300 KB, sent as a data URL, kept on the public disk under a random name. */
class AvatarStore
{
    public const MAX_BYTES = 300 * 1024;

    private const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    /** Stores the picture for the user (replacing the old file) and returns its path. */
    public static function save(User $user, string $dataUrl): string
    {
        if (! preg_match('#^data:(image/(?:png|jpeg|webp));base64,([A-Za-z0-9+/=\s]+)$#', $dataUrl, $m)) {
            throw ValidationException::withMessages(['avatar' => 'The picture must be a PNG, JPEG or WebP image.']);
        }
        $bytes = base64_decode($m[2], true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['avatar' => 'The picture must be under 300 KB.']);
        }
        $info = @getimagesizefromstring($bytes);
        if (! $info || ! isset(self::TYPES[$info['mime']])) {
            throw ValidationException::withMessages(['avatar' => 'That file is not a valid image.']);
        }

        $path = 'avatars/'.Str::random(40).'.'.self::TYPES[$info['mime']];
        Storage::disk('public')->put($path, $bytes);
        self::delete($user->avatar_url);

        return $path;
    }

    public static function delete(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }

    public static function url(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }
}
