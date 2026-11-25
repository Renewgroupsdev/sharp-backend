<?php

namespace App\Models;

use App\Enums\AssessmentStatus;
use App\Enums\AssessmentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A quiz, assignment or assessment. A quiz draws its questions (and so its marks) from its question groups. */
class Assessment extends Model
{
    protected $fillable = [
        'type', 'title', 'course', 'module', 'topic', 'status', 'due_date', 'max_marks', 'min_percent',
        'attempts', 'description', 'attachment_name', 'attachment_size', 'attachment_path', 'attachment_disk', 'attachment_mime', 'setup',
    ];

    protected function casts(): array
    {
        return [
            'type' => AssessmentType::class,
            'status' => AssessmentStatus::class,
            'due_date' => 'date',
            'setup' => 'array',
        ];
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(QuestionGroup::class, 'assessment_question_group');
    }

    public function results(): HasMany
    {
        return $this->hasMany(QuizResult::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(AssessmentSubmission::class);
    }

    /** The brief as clients see it: display values only, never the storage path. `available` is false for legacy rows that kept a name but no file. */
    public function attachmentSummary(): ?array
    {
        return $this->attachment_name
            ? ['name' => $this->attachment_name, 'size' => $this->attachment_size, 'mime' => $this->attachment_mime, 'available' => $this->attachment_path !== null]
            : null;
    }

    /** How many attempts a student may make: null for unlimited (multiple attempts on), 1 otherwise. */
    public function attemptLimit(): ?int
    {
        return ($this->setup['multi'] ?? true) ? null : 1;
    }

    public function isQuiz(): bool
    {
        return $this->type === AssessmentType::Quiz;
    }

    /** Quizzes and assessments are answered question by question; assignments are handed in as a submission. */
    public function usesQuestions(): bool
    {
        return $this->type === AssessmentType::Quiz || $this->type === AssessmentType::Assessment;
    }

    /** How many questions the quiz draws from its groups. */
    public function quizQuestionCount(): int
    {
        return Question::whereIn('question_group_id', $this->groups()->pluck('question_groups.id'))->count();
    }

    /** Sum of the marks of every question in the chosen groups. */
    public function quizTotal(): int
    {
        return (int) Question::whereIn('question_group_id', $this->groups()->pluck('question_groups.id'))->sum('marks');
    }
}
