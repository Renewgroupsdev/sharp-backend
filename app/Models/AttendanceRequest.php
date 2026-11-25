<?php

namespace App\Models;

use App\Enums\AttendanceChoice;
use App\Enums\AttendanceRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRequest extends Model
{
    protected $fillable = [
        'student_id', 'att_date', 'requested_status', 'reason', 'status', 'decision', 'resolved_by', 'resolved_at', 'resolution_note',
        'lat', 'lng', 'distance_m', 'on_site',
    ];

    protected function casts(): array
    {
        return [
            'att_date' => 'date',
            'requested_status' => AttendanceChoice::class,
            'status' => AttendanceRequestStatus::class,
            'decision' => AttendanceChoice::class,
            'resolved_at' => 'datetime',
            'on_site' => 'boolean',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isPending(): bool
    {
        return $this->status === AttendanceRequestStatus::Pending;
    }
}
