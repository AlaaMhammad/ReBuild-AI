<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Expired tokens are already rejected by the guard; this keeps the table small.
// The 24h grace period preserves recently expired rows for incident forensics.
Schedule::command('sanctum:prune-expired', ['--hours' => 24])
    ->daily()
    ->onOneServer()
    ->withoutOverlapping();

// Recovers batch/image post-processing lost to worker crashes or queue loss.
Schedule::command('rebuild:sync:recover-stale', ['--minutes' => 15])
    ->everyTenMinutes()
    ->onOneServer()
    ->withoutOverlapping();

// Horizon dashboard metrics.
Schedule::command('horizon:snapshot')->everyFiveMinutes();
