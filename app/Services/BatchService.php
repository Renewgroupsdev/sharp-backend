<?php

namespace App\Services;

use App\Enums\Role;
use App\Support\StudentScope;
use App\Models\Batch;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class BatchService
{
    public function __construct(private readonly AuditLogger $audit, private readonly EnrollmentService $enrollments) {}

    /** @return Collection<int, Batch> */
    /** Every batch; an institution account gets the batches its own students are in (or that list its institute). */
    public function list(?User $actor = null): Collection
    {
        return Batch::query()->with(['colleges:id,name', 'courses:id,title'])->withCount('students')
            ->when($actor?->role === Role::Institution, fn ($q) => $q->where(fn ($w) => $w
                ->whereIn('id', StudentScope::collegeBatchIds($actor))
                ->orWhereHas('colleges', fn ($c) => $c->where('colleges.id', $actor->college_id ?? 0))))
            ->orderByDesc('start_date')->get();
    }

    public function create(User $actor, array $data): Batch
    {
        $batch = Batch::create($this->fields($data));
        $batch->colleges()->sync($data['college_ids'] ?? []);
        $this->enrollments->recordBatchChanges($actor, $batch->id, $batch->courses()->sync($data['course_ids'] ?? []), 'batch form');
        $batch = $this->fresh($batch);

        $this->audit->record($actor, 'Added batch', (string) $batch->id, $batch->name);

        return $batch;
    }

    public function update(User $actor, Batch $batch, array $data): Batch
    {
        $batch->update($this->fields($data));
        if (array_key_exists('college_ids', $data)) {
            $batch->colleges()->sync($data['college_ids'] ?? []);
        }
        if (array_key_exists('course_ids', $data)) {
            $this->enrollments->recordBatchChanges($actor, $batch->id, $batch->courses()->sync($data['course_ids'] ?? []), 'batch form');
        }
        $batch = $this->fresh($batch);

        $this->audit->record($actor, 'Updated batch', (string) $batch->id, $batch->name);

        return $batch;
    }

    public function delete(User $actor, Batch $batch): void
    {
        $count = $batch->users()->count();
        if ($count > 0) {
            throw ValidationException::withMessages(['batch' => "{$count} user(s) are in {$batch->name} — move them first."]);
        }

        $name = $batch->name;
        $id = (string) $batch->id;
        $batch->delete();

        $this->audit->record($actor, 'Deleted batch', $id, $name);
    }

    private function fields(array $data): array
    {
        return array_filter([
            'name' => $data['name'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'seats' => $data['seats'],
            'mentors' => $data['mentors'] ?? 0,
            'status' => $data['status'] ?? null,
        ], fn ($v) => $v !== null);
    }

    private function fresh(Batch $batch): Batch
    {
        return $batch->refresh()->load(['colleges:id,name', 'courses:id,title'])->loadCount('students');
    }
}
