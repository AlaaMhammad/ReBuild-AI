<?php

declare(strict_types=1);

namespace App\Services\Sync;

/**
 * Per-item results of ingesting one batch, in the exact shape returned to the
 * device and persisted in sync_batches.result_summary (so a replayed request
 * receives a byte-for-byte equivalent answer).
 *
 * Item states:
 *  - created / updated : the server now holds the device's revision.
 *  - skipped           : acknowledged without change (the server already holds an
 *                        equal or newer state, or the record is locked by review).
 *  - failed            : not stored; "retryable" tells the device whether to resend.
 *
 * Devices mark created, updated and skipped items as synced and keep failed
 * items queued only when retryable is true.
 */
final class BatchOutcome
{
    public const BENEFICIARIES = 'beneficiaries';

    public const ASSESSMENTS = 'assessments';

    public const IMAGES = 'images';

    public const ENTITIES = [self::BENEFICIARIES, self::ASSESSMENTS, self::IMAGES];

    /**
     * @var array<string, array{created: list<string>, updated: list<string>, skipped: list<array{client_uuid: string, reason: string}>, failed: list<array<string, mixed>>, warnings: list<array{client_uuid: string, code: string, message: string}>}>
     */
    private array $results = [];

    /**
     * @var array<string, array<string, string>>
     */
    private array $idMap = [];

    /**
     * @var list<string>
     */
    private array $imagesAwaitingUpload = [];

    public function __construct()
    {
        foreach (self::ENTITIES as $entity) {
            $this->results[$entity] = ['created' => [], 'updated' => [], 'skipped' => [], 'failed' => [], 'warnings' => []];
            $this->idMap[$entity] = [];
        }
    }

    public function created(string $entity, string $clientUuid, string $serverId): void
    {
        $this->results[$entity]['created'][] = $clientUuid;
        $this->idMap[$entity][$clientUuid] = $serverId;
    }

    public function updated(string $entity, string $clientUuid, string $serverId): void
    {
        $this->results[$entity]['updated'][] = $clientUuid;
        $this->idMap[$entity][$clientUuid] = $serverId;
    }

    public function skipped(string $entity, string $clientUuid, SkipReason $reason, ?string $serverId = null): void
    {
        $this->results[$entity]['skipped'][] = ['client_uuid' => $clientUuid, 'reason' => $reason->value];

        if ($serverId !== null) {
            $this->idMap[$entity][$clientUuid] = $serverId;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function failed(string $entity, string $clientUuid, FailureCode $code, string $message, array $context = []): void
    {
        $this->results[$entity]['failed'][] = [
            'client_uuid' => $clientUuid,
            'code' => $code->value,
            'message' => $message,
            'retryable' => $code->isRetryable(),
            ...($context === [] ? [] : ['context' => $context]),
        ];
    }

    public function warning(string $entity, string $clientUuid, string $code, string $message): void
    {
        $this->results[$entity]['warnings'][] = ['client_uuid' => $clientUuid, 'code' => $code, 'message' => $message];
    }

    public function awaitingUpload(string $clientUuid): void
    {
        $this->imagesAwaitingUpload[] = $clientUuid;
    }

    /**
     * Server id of a record created/updated/skipped earlier in this batch.
     */
    public function serverIdFor(string $entity, string $clientUuid): ?string
    {
        return $this->idMap[$entity][$clientUuid] ?? null;
    }

    public function hasFailed(string $entity, string $clientUuid): bool
    {
        foreach ($this->results[$entity]['failed'] as $failure) {
            if ($failure['client_uuid'] === $clientUuid) {
                return true;
            }
        }

        return false;
    }

    public function createdCount(): int
    {
        return $this->sum('created');
    }

    public function updatedCount(): int
    {
        return $this->sum('updated');
    }

    public function skippedCount(): int
    {
        return $this->sum('skipped');
    }

    public function failedCount(): int
    {
        return $this->sum('failed');
    }

    /**
     * @return list<string>
     */
    public function acknowledgedClientUuids(): array
    {
        $acknowledged = [];

        foreach ($this->results as $result) {
            array_push(
                $acknowledged,
                ...$result['created'],
                ...$result['updated'],
                ...array_column($result['skipped'], 'client_uuid'),
            );
        }

        return $acknowledged;
    }

    /**
     * @return array{results: array<string, mixed>, acknowledged_client_uuids: list<string>, id_map: array<string, array<string, string>>, images_awaiting_upload: list<string>}
     */
    public function toArray(): array
    {
        return [
            'results' => $this->results,
            'acknowledged_client_uuids' => $this->acknowledgedClientUuids(),
            'id_map' => $this->idMap,
            'images_awaiting_upload' => $this->imagesAwaitingUpload,
        ];
    }

    private function sum(string $state): int
    {
        return array_sum(array_map(static fn (array $result): int => count($result[$state]), $this->results));
    }
}
