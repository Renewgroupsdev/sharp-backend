<?php

namespace App\Models;

use App\Enums\EnrollmentEventType;
use Illuminate\Database\Eloquent\Model;

class EnrollmentEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['course_id', 'batch_id', 'event', 'source', 'actor_id', 'note'];

    protected function casts(): array
    {
        return ['event' => EnrollmentEventType::class, 'created_at' => 'datetime'];
    }
}
