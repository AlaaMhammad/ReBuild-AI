<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Models\SyncBatch;

final class SyncSubmission
{
    public function __construct(
        public readonly SyncBatch $batch,
        public readonly bool $replayed,
    ) {}
}
