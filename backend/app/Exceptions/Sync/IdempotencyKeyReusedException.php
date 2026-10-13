<?php

declare(strict_types=1);

namespace App\Exceptions\Sync;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * The idempotency key was already used for a batch with different content.
 * This is always a client bug (keys must be generated per batch and reused
 * only for byte-identical retries) and is never merged silently.
 */
final class IdempotencyKeyReusedException extends RuntimeException
{
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly string $existingBatchId,
    ) {
        parent::__construct("Idempotency key [{$idempotencyKey}] was already used for a different payload.");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => 'This idempotency key was already used for a batch with different content. Generate a new key for new content.',
            'code' => 'idempotency_key_reused',
            'batch_id' => $this->existingBatchId,
        ], 422);
    }
}
