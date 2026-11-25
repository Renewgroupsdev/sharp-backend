<?php

namespace App\Models;

use App\Enums\CollegeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class College extends Model
{
    protected $fillable = ['name', 'code', 'city', 'principal_name', 'email', 'mobile', 'status', 'lat', 'lng', 'geofence_radius_m'];

    protected function casts(): array
    {
        return ['status' => CollegeStatus::class];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function students(): HasMany
    {
        return $this->users()->where('role', 'student');
    }
}
