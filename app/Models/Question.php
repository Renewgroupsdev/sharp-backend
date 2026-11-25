<?php

namespace App\Models;

use App\Enums\QuestionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Question extends Model
{
    protected $fillable = ['question_group_id', 'type', 'marks', 'text', 'image', 'data', 'explanation'];

    protected function casts(): array
    {
        return ['type' => QuestionType::class, 'data' => 'array', 'marks' => 'integer'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(QuestionGroup::class, 'question_group_id');
    }
}
