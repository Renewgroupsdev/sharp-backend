<?php

namespace App\Models;

use App\Enums\CertificateStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Certificate extends Model
{
    /** The reasons staff can pick when cancelling an issued certificate. */
    public const CANCEL_REASONS = [
        'Issued in error',
        'Wrong details on the certificate',
        'Marks or attendance corrected',
        'Duplicate certificate',
        'Student request',
        'Misconduct or policy breach',
        'Other',
    ];

    public const OTHER_REASON = 'Other';

    protected $fillable = [
        'user_id', 'course_id', 'number', 'status', 'issued_by', 'issued_at',
        'mentor_decision', 'mentor_note', 'mentor_reviewed_by', 'mentor_reviewed_at',
        'score', 'attendance', 'eligibility_override', 'design', 'cancelled_by', 'cancelled_at', 'cancel_reason', 'cancel_note',
        'issue_date', 'score_override', 'design_override', 'min_attendance_override',
    ];

    protected function casts(): array
    {
        return [
            'status' => CertificateStatus::class,
            'issued_at' => 'datetime',
            'mentor_reviewed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'eligibility_override' => 'boolean',
            'design' => 'array',
            'issue_date' => 'date',
            'score_override' => 'integer',
            'design_override' => 'array',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function mentorReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mentor_reviewed_by');
    }
}
