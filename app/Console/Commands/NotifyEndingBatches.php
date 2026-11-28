<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Batch;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Tells the programme office (Super Admin, Admin, OB) that a batch is about to end: 7 days, 3 days, 1 day before and on the end date.
 */
class NotifyEndingBatches extends Command
{
    private const DAYS = [7 => 'ends in 7 days', 3 => 'ends in 3 days', 1 => 'ends tomorrow', 0 => 'ends today'];

    protected $signature = 'batches:notify-ending';

    protected $description = 'Notify the office about batches whose end date is 7, 3, 1 or 0 days away';

    public function handle(NotificationService $notifications): int
    {
        $staff = User::query()->where('status', '<>', UserStatus::Suspended->value)
            ->whereIn('role', array_map(fn (Role $r) => $r->value, Role::staff()))->pluck('id')->all();
        $sent = 0;

        foreach (self::DAYS as $days => $when) {
            foreach (Batch::where('status', '<>', 'closed')->whereDate('end_date', today()->addDays($days))->get() as $batch) {
                $notifications->notify('batch_ending', $staff, ['batch' => $batch->name, 'when' => $when, 'date' => $batch->end_date->format('d M Y')], ['url' => '/app/institutes']);
                $sent++;
            }
        }

        $this->info("{$sent} batch(es) announced.");

        return self::SUCCESS;
    }
}
