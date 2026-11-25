<?php

namespace App\Models;

use App\Enums\Role;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'status' => UserStatus::class,
            'must_change_password' => 'boolean',
            'last_login' => 'datetime',
            'last_seen_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(self::class, 'created_by');
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(Attendance::class, 'student_id');
    }

    public function isSuspended(): bool
    {
        return $this->status === UserStatus::Suspended;
    }

    public function hasRole(Role ...$roles): bool
    {
        return $this->role->isIn(...$roles);
    }

    public function scopeStudents(Builder $q): Builder
    {
        return $q->where('role', Role::Student->value);
    }

    public function scopeFilter(Builder $q, array $f): Builder
    {
        return $q
            ->when(! empty($f['role']) && $f['role'] !== 'All', fn ($q) => $q->where('role', strtolower($f['role'])))
            ->when(! empty($f['college_id']), fn ($q) => $q->where('college_id', (int) $f['college_id']))
            ->when(! empty($f['status']), fn ($q) => $q->where('status', $f['status']))
            ->when(! empty($f['q']), function ($q) use ($f) {
                $like = '%'.addcslashes($f['q'], '%_\\').'%';
                $q->where(fn ($q) => $q
                    ->where('name', 'ilike', $like)
                    ->orWhere('username', 'ilike', $like)
                    ->orWhere('public_id', 'ilike', $like));
            });
    }
}
