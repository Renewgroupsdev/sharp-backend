<?php

namespace Database\Seeders;

use App\Enums\BatchStatus;
use App\Models\Batch;
use App\Models\College;
use Illuminate\Database\Seeder;

/** Programme batches. Run after colleges exist; an empty college list means every institute takes part. */
class BatchSeeder extends Seeder
{
    public function run(): void
    {
        $colleges = College::pluck('id', 'code');

        foreach ([
            ['SHARP Batch 05', '2025-07-12', '2025-12-21', 48, 4, BatchStatus::Closed, []],
            ['SHARP Batch 06', '2026-01-10', '2026-06-20', 64, 6, BatchStatus::Closed, []],
            ['SHARP Batch 07', '2026-07-11', '2026-12-20', 64, 8, BatchStatus::Running, ['FCM', 'THC', 'LDC', 'AMC']],
            ['SHARP Batch 08', '2027-01-09', '2027-06-19', 60, 0, BatchStatus::Enrolling, ['FCM', 'LDC']],
        ] as [$name, $start, $end, $seats, $mentors, $status, $codes]) {
            $batch = Batch::updateOrCreate(['name' => $name], [
                'start_date' => $start,
                'end_date' => $end,
                'seats' => $seats,
                'mentors' => $mentors,
                'status' => $status,
            ]);

            $batch->colleges()->sync($colleges->only($codes)->values()->all());
        }
    }
}
