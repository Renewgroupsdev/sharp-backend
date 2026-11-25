<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubmissionAttachment extends Model
{
    protected $fillable = ['submission_id', 'original_name', 'storage_path', 'disk', 'mime_type', 'file_size'];

    public function submission(): BelongsTo { return $this->belongsTo(AssessmentSubmission::class, 'submission_id'); }
}
