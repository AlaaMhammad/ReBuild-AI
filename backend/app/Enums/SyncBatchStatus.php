<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum SyncBatchStatus: string
{
    use HasValues;

    case Received = 'received';
    case Processing = 'processing';
    case Completed = 'completed';
    case PartiallyFailed = 'partially_failed';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::PartiallyFailed, self::Failed => true,
            self::Received, self::Processing => false,
        };
    }
}
