<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizAttempt extends Model
{
    public const IN_PROGRESS = 'in_progress';
    public const TIMED_OUT = 'timed_out';
    public const NEEDS_REVIEW = 'needs_review';
    public const PASSED = 'passed';
    public const NOT_PASSED = 'not_passed';

    /** Every outcome, in the order a report lists them. */
    public const OUTCOMES = [self::PASSED, self::NOT_PASSED, self::NEEDS_REVIEW, self::TIMED_OUT, self::IN_PROGRESS];

    protected $fillable = [
        'assessment_id', 'student_id', 'attempt_no', 'question_order', 'started_at', 'expires_at', 'submitted_at',
        'marks', 'total', 'result_percent', 'passed', 'time_taken_sec', 'needs_manual_grading', 'answers',
    ];

    protected function casts(): array
    {
        return [
            'question_order' => 'array',
            'answers' => 'array',
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'passed' => 'boolean',
            'needs_manual_grading' => 'boolean',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /**
     * SQL for "the system closed this attempt because time ran out": closed exactly at its deadline and never marked
     * (total stays 0). An attempt the timer auto-submitted at the deadline has been marked (total > 0), so it is not this.
     */
    public const TIMED_OUT_SQL = 'quiz_attempts.expires_at IS NOT NULL AND quiz_attempts.submitted_at = quiz_attempts.expires_at AND COALESCE(quiz_attempts.total, 0) = 0';

    /**
     * Where the attempt stands. A timed-out attempt is one the system closed because the student never submitted
     * (see TIMED_OUT_SQL); long answers a mentor still has to mark come before pass / fail.
     * Keep scopeOutcome() in step with this.
     */
    public function outcome(): string
    {
        return match (true) {
            $this->submitted_at === null => self::IN_PROGRESS,
            $this->expires_at !== null && $this->submitted_at->equalTo($this->expires_at) && (int) ($this->total ?? 0) === 0 => self::TIMED_OUT,
            (bool) $this->needs_manual_grading => self::NEEDS_REVIEW,
            (bool) $this->passed => self::PASSED,
            default => self::NOT_PASSED,
        };
    }

    /** The same rule as outcome(), as SQL, so a list can filter and count by it. */
    public function scopeOutcome(Builder $query, string $outcome): Builder
    {
        $timedOut = self::TIMED_OUT_SQL;
        $real = fn (Builder $q) => $q->whereNotNull('quiz_attempts.submitted_at')->whereRaw("NOT ($timedOut)");

        return match ($outcome) {
            self::IN_PROGRESS => $query->whereNull('quiz_attempts.submitted_at'),
            self::TIMED_OUT => $query->whereNotNull('quiz_attempts.submitted_at')->whereRaw($timedOut),
            self::NEEDS_REVIEW => $real($query)->where('quiz_attempts.needs_manual_grading', true),
            self::PASSED => $real($query)->where('quiz_attempts.needs_manual_grading', false)->where('quiz_attempts.passed', true),
            self::NOT_PASSED => $real($query)->where('quiz_attempts.needs_manual_grading', false)
                ->where(fn (Builder $q) => $q->where('quiz_attempts.passed', false)->orWhereNull('quiz_attempts.passed')),
            default => $query,
        };
    }

    public function isOpen(): bool
    {
        return $this->submitted_at === null;
    }
}
