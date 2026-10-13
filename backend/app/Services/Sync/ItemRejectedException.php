<?php

declare(strict_types=1);

namespace App\Services\Sync;

use RuntimeException;

/**
 * An item was rejected before being persisted (invalid image bytes, storage
 * outage, ...). Carries the FailureCode that is reported to the device.
 */
final class ItemRejectedException extends RuntimeException
{
    public function __construct(
        public readonly FailureCode $failureCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
