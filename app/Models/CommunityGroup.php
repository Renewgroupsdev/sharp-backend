<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A chat or announcement group. See the community_groups migration for how membership works. */
class CommunityGroup extends Model
{
    use SoftDeletes;

    public const CHAT = 'Chat';

    public const ANNOUNCEMENT = 'Announcement';

    public const KINDS = [self::CHAT, self::ANNOUNCEMENT];

    public const FLOOR = 'floor';

    public const COURSE = 'course';

    public const CUSTOM = 'custom';

    public const DM = 'dm';

    public const FLOOR_NAME = 'Common Floor';

    public const ALL_USERS_NAME = 'All users';

    protected $fillable = ['name', 'kind', 'source', 'course_id', 'created_by'];

    /** Whether a custom group already uses this name (ignoring case). `$ignoreId` lets a group keep its own name. */
    public static function nameTaken(string $name, ?int $ignoreId = null): bool
    {
        return static::query()->where('source', self::CUSTOM)->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists();
    }

    /** The people added by hand: custom group members, extras on a course group, the two people of a personal chat. */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'community_group_members', 'group_id', 'user_id')->withPivot('added_by', 'created_at');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(CommunityMessage::class, 'group_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDm(): bool
    {
        return $this->source === self::DM;
    }

    public function isFloor(): bool
    {
        return $this->source === self::FLOOR;
    }

    public function isAnnouncement(): bool
    {
        return $this->kind === self::ANNOUNCEMENT;
    }

    /** The one chat whose messages live in common_floor_messages, shared with the mobile app. */
    public function usesFloorMessages(): bool
    {
        return $this->isFloor() && ! $this->isAnnouncement();
    }
}
