<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    protected $table = 'attendance';

    protected $fillable = ['student_id', 'att_date', 'status', 'reason', 'marked_by', 'lat', 'lng', 'distance_m', 'on_site'];

    protected function casts(): array
    {
        return [
            'att_date' => 'date:Y-m-d',
            'status' => AttendanceStatus::class,
            'on_site' => 'boolean',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function marker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }
}
