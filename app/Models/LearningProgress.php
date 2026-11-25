<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LearningProgress extends Model
{
    protected $table = 'learning_progress';

    protected $fillable = ['user_id', 'course_id', 'progress', 'materials_completed', 'last_accessed_at'];

    protected function casts(): array
    {
        return [
            'progress' => 'integer',
            'materials_completed' => 'integer',
            'last_accessed_at' => 'datetime',
        ];
    }
}
