<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('attendance:notify-pending')->dailyAt('11:00');
Schedule::command('batches:notify-ending')->dailyAt('09:00');
