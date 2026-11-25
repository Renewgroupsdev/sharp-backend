<?php

namespace App\Models;

use App\Enums\BatchStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Batch extends Model
{
    protected $fillable = ['name', 'start_date', 'end_date', 'seats', 'mentors', 'status'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'status' => BatchStatus::class,
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function students(): HasMany
    {
        return $this->users()->where('role', 'student');
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'batch_course')->withTimestamps();
    }

    public function colleges(): BelongsToMany
    {
        return $this->belongsToMany(College::class);
    }
}
