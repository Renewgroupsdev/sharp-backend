<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Assessment;
use App\Models\User;
use App\Support\Uploads;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The instructor's brief for an assignment or assessment: one private file, stored by disk + path on the assessment row. */
class AssessmentAttachmentService
{
    public function __construct(private readonly AuditLogger $audit, private readonly MyAssessmentService $mine) {}

    /** Stores the file (replacing any earlier one) and records where it lives. */
    public function store(User $actor, Assessment $assessment, UploadedFile $file): Assessment
    {
        abort_if($assessment->usesQuestions(), 422, 'A quiz or assessment cannot have an attachment.');

        $disk = config('filesystems.assessment_disk', 'local');
        $path = $file->store("assessment-briefs/{$assessment->id}", $disk);
        abort_unless($path, 500, 'Unable to store the uploaded file.');

        $previous = $this->location($assessment);
        try {
            $assessment->update([
                'attachment_name' => $file->getClientOriginalName(),
                'attachment_size' => Uploads::humanSize((int) $file->getSize()),
                'attachment_path' => $path,
                'attachment_disk' => $disk,
                'attachment_mime' => $file->getMimeType(),
            ]);
        } catch (\Throwable $e) {
            $this->purge([$disk, $path]); // the row did not change, so the new file would be orphaned
            throw $e;
        }
        $this->purge($previous);

        $this->audit->record($actor, 'Uploaded assessment file', (string) $assessment->id, $assessment->title);

        return $assessment->refresh();
    }

    public function remove(User $actor, Assessment $assessment): Assessment
    {
        $this->clear($assessment);
        $this->audit->record($actor, 'Removed assessment file', (string) $assessment->id, $assessment->title);

        return $assessment->refresh();
    }

    /** Drops the file and its columns without an audit entry; used when an assessment becomes a quiz or is deleted. */
    public function clear(Assessment $assessment): void
    {
        $previous = $this->location($assessment);
        $assessment->forceFill([
            'attachment_name' => null, 'attachment_size' => null,
            'attachment_path' => null, 'attachment_disk' => null, 'attachment_mime' => null,
        ]);
        if ($assessment->exists) {
            $assessment->save();
        }
        $this->purge($previous);
    }

    /** Streams the file. Staff and mentors may open any; a student only a published assessment of their own course. */
    public function respond(User $actor, Assessment $assessment, bool $download = false): StreamedResponse
    {
        if ($actor->role === Role::Student) {
            abort_unless($this->mine->visible($actor)->whereKey($assessment->id)->exists(), 404, 'File not found.');
        }
        $location = $this->location($assessment);
        abort_unless($location && Storage::disk($location[0])->exists($location[1]), 404, 'File not found.');

        return Storage::disk($location[0])->response($location[1], $assessment->attachment_name, [
            'Content-Type' => $assessment->attachment_mime ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
        ], $download ? 'attachment' : 'inline');
    }

    /** @return array{0: string, 1: string}|null disk and path of the stored file */
    private function location(Assessment $assessment): ?array
    {
        return $assessment->attachment_path ? [$assessment->attachment_disk ?: config('filesystems.assessment_disk', 'local'), $assessment->attachment_path] : null;
    }

    /** @param array{0: string, 1: string}|null $location */
    private function purge(?array $location): void
    {
        if ($location) {
            Storage::disk($location[0])->delete($location[1]);
        }
    }
}
