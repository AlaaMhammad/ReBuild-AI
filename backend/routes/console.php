<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Expired tokens are already rejected by the guard; this keeps the table small.
// The 24h grace period preserves recently expired rows for incident forensics.
Schedule::command('sanctum:prune-expired', ['--hours' => 24])
    ->daily()
    ->onOneServer()
    ->withoutOverlapping();
