<?php

declare(strict_types=1);

namespace App\Exceptions\Sync;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Another request holding the same idempotency key is still being ingested
 * (typically a client retry fired while the first attempt is in flight).
 */
final class SyncBatchInProgressException extends RuntimeException
{
    public const RETRY_AFTER_SECONDS = 5;

    public function __construct(public readonly string $idempotencyKey)
    {
        parent::__construct("Batch [{$idempotencyKey}] is already being processed.");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => 'This batch is already being processed. Retry the same request shortly to receive its result.',
            'code' => 'sync_in_progress',
        ], 409, ['Retry-After' => (string) self::RETRY_AFTER_SECONDS]);
    }
}
