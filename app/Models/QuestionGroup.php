<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuestionGroup extends Model
{
    protected $fillable = ['title', 'type'];

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    public function quizzes(): BelongsToMany
    {
        return $this->belongsToMany(Assessment::class, 'assessment_question_group');
    }
}
