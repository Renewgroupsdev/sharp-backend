<?php

namespace App\Models;

use App\Enums\SubmissionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssessmentSubmission extends Model
{
    protected $fillable = [
        'assessment_id', 'student_id', 'submission_no', 'content', 'status', 'marks_obtained',
        'feedback', 'graded_by', 'submitted_at', 'graded_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubmissionStatus::class,
            'marks_obtained' => 'decimal:2',
            'submitted_at' => 'datetime',
            'graded_at' => 'datetime',
        ];
    }

    public function assessment(): BelongsTo { return $this->belongsTo(Assessment::class); }
    public function student(): BelongsTo { return $this->belongsTo(User::class, 'student_id'); }
    public function grader(): BelongsTo { return $this->belongsTo(User::class, 'graded_by'); }
    public function attachments(): HasMany { return $this->hasMany(SubmissionAttachment::class, 'submission_id'); }
}
