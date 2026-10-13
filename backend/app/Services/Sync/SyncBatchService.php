<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Enums\SyncBatchStatus;
use App\Exceptions\Sync\IdempotencyKeyReusedException;
use App\Exceptions\Sync\SyncBatchInProgressException;
use App\Jobs\ProcessSyncBatchJob;
use App\Models\PersonalAccessToken;
use App\Models\SyncBatch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Entry point for POST /api/v1/sync/batch.
 *
 * Idempotency is enforced at two layers:
 *  1. a cache lock on (organization, idempotency_key) serialises concurrent
 *     retries of the same batch, so the first attempt ingests and the others
 *     either wait for a replay or get 409 sync_in_progress;
 *  2. UNIQUE (organization_id, idempotency_key) on sync_batches is the durable
 *     guarantee: even if the lock store is flushed, a key can only be recorded
 *     once, and the loser of that race replays the winner's result.
 *
 * A known key with the same payload hash is a replay (the device lost the
 * first response); with a different payload hash it is rejected.
 */
final class SyncBatchService
{
    public function __construct(
        private readonly SyncBatchIngestor $ingestor,
        private readonly ImageBinaryStore $binaries,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  Validated BatchSyncRequest data
     *
     * @throws SyncBatchInProgressException
     * @throws IdempotencyKeyReusedException
     */
    public function submit(
        User $user,
        ?PersonalAccessToken $token,
        array $payload,
        string $rawBody,
        ?string $ipAddress,
        ?string $userAgent,
    ): SyncSubmission {
        $organizationId = $user->organization_id;
        $idempotencyKey = strtolower((string) $payload['idempotency_key']);
        $payloadHash = hash('sha256', $rawBody);

        $lock = Cache::lock("sync-batch:{$organizationId}:{$idempotencyKey}", (int) config('rebuild.sync.lock_seconds'));

        if (! $lock->get()) {
            throw new SyncBatchInProgressException($idempotencyKey);
        }

        try {
            $existing = $this->find($organizationId, $idempotencyKey);

            if ($existing !== null) {
                return $this->replay($existing, $idempotencyKey, $payloadHash);
            }

            try {
                return $this->ingestNew($user, $token, $payload, $idempotencyKey, $payloadHash, $ipAddress, $userAgent);
            } catch (UniqueConstraintViolationException $exception) {
                $existing = $this->find($organizationId, $idempotencyKey);

                if ($existing === null) {
                    throw $exception;
                }

                return $this->replay($existing, $idempotencyKey, $payloadHash);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function ingestNew(
        User $user,
        ?PersonalAccessToken $token,
        array $payload,
        string $idempotencyKey,
        string $payloadHash,
        ?string $ipAddress,
        ?string $userAgent,
    ): SyncSubmission {
        $receivedAt = CarbonImmutable::now();
        $sentAt = CarbonImmutable::parse((string) $payload['sent_at'])->utc();
        $clock = DeviceClock::forBatch($sentAt, $receivedAt);

        try {
            return DB::transaction(function () use ($user, $token, $payload, $idempotencyKey, $payloadHash, $ipAddress, $userAgent, $receivedAt, $sentAt, $clock): SyncSubmission {
                $batch = new SyncBatch([
                    'idempotency_key' => $idempotencyKey,
                    'payload_hash' => $payloadHash,
                    'device_id' => (string) $payload['device']['id'],
                    'device_platform' => $payload['device']['platform'] ?? null,
                    'app_version' => $payload['device']['app_version'] ?? null,
                    'total_items' => count($payload['beneficiaries'] ?? []) + count($payload['assessments'] ?? []) + count($payload['images'] ?? []),
                    'client_sent_at' => $sentAt,
                    'received_at' => $receivedAt,
                    'ip_address' => $ipAddress,
                    'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 1000),
                ]);
                $batch->organization_id = $user->organization_id;
                $batch->user_id = $user->id;
                $batch->personal_access_token_id = $token?->id;
                $batch->status = SyncBatchStatus::Processing;
                $batch->clock_skew_seconds = $clock->skewSeconds;
                $batch->processing_started_at = $receivedAt;
                $batch->save();

                $outcome = $this->ingestor->ingest($batch, $user, $payload, $clock);

                $batch->forceFill([
                    'created_count' => $outcome->createdCount(),
                    'updated_count' => $outcome->updatedCount(),
                    'skipped_count' => $outcome->skippedCount(),
                    'failed_count' => $outcome->failedCount(),
                    'result_summary' => [
                        ...$outcome->toArray(),
                        'clock' => [
                            'skew_seconds' => $clock->skewSeconds,
                            'corrected' => $clock->isCorrecting(),
                        ],
                    ],
                ])->save();

                ProcessSyncBatchJob::dispatch($batch->id)->afterCommit();

                return new SyncSubmission($batch, replayed: false);
            });
        } catch (Throwable $exception) {
            // The transaction rolled back: remove binaries written for it.
            foreach ($this->ingestor->writtenFiles() as $file) {
                $this->binaries->delete($file['disk'], $file['path']);
            }

            throw $exception;
        }
    }

    private function replay(SyncBatch $batch, string $idempotencyKey, string $payloadHash): SyncSubmission
    {
        if (! $batch->matchesPayloadHash($payloadHash)) {
            throw new IdempotencyKeyReusedException($idempotencyKey, $batch->id);
        }

        return new SyncSubmission($batch, replayed: true);
    }

    private function find(string $organizationId, string $idempotencyKey): ?SyncBatch
    {
        return SyncBatch::query()
            ->forOrganization($organizationId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }
}
