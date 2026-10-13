<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ImageSyncStatus;
use App\Enums\SyncBatchStatus;
use App\Models\AssessmentImage;
use App\Models\SyncBatch;
use App\Services\Sync\AssessmentImageProcessor;
use App\Services\Sync\BatchOutcome;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Asynchronous half of a sync batch (Horizon "sync" queue):
 *  - indexes the image binaries delivered inline with the batch;
 *  - moves the batch from "processing" to its terminal status.
 *
 * Idempotent: re-running it on a terminal batch is a no-op, and images that
 * are already processed are skipped, so retries and the stale-batch recovery
 * command can dispatch it freely.
 *
 * Phase 3 (deduplication and priority scoring) hooks in here.
 */
final class ProcessSyncBatchJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly string $syncBatchId,
    ) {
        $this->onQueue((string) config('rebuild.sync.queue'));
    }

    public function uniqueId(): string
    {
        return $this->syncBatchId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 120, 300];
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['sync-batch:'.$this->syncBatchId];
    }

    public function handle(TenantContext $tenant, AssessmentImageProcessor $processor): void
    {
        $batch = SyncBatch::query()->withoutGlobalScopes()->find($this->syncBatchId);

        if ($batch === null || $batch->status->isTerminal()) {
            return;
        }

        $tenant->runAs($batch->organization_id, function () use ($batch, $processor): void {
            $imageIds = array_values($batch->result_summary['id_map'][BatchOutcome::IMAGES] ?? []);

            if ($imageIds !== []) {
                AssessmentImage::query()
                    ->whereIn('id', $imageIds)
                    ->whereIn('sync_status', [ImageSyncStatus::Uploaded->value, ImageSyncStatus::Processing->value])
                    ->orderBy('id')
                    ->each(static fn (AssessmentImage $image) => $processor->process($image));
            }

            $batch->forceFill([
                'status' => $batch->resolveTerminalStatus(),
                'completed_at' => CarbonImmutable::now(),
            ])->save();
        });
    }

    /**
     * Ingested records are already committed and acknowledged; only the
     * post-processing failed. Record that without discarding the outcome.
     */
    public function failed(?Throwable $exception): void
    {
        $batch = SyncBatch::query()->withoutGlobalScopes()->find($this->syncBatchId);

        if ($batch === null || $batch->status->isTerminal()) {
            return;
        }

        $status = $batch->resolveTerminalStatus() === SyncBatchStatus::Failed
            ? SyncBatchStatus::Failed
            : SyncBatchStatus::PartiallyFailed;

        $batch->forceFill([
            'status' => $status,
            'completed_at' => CarbonImmutable::now(),
            'error_message' => mb_substr('Post-processing failed: '.($exception?->getMessage() ?? 'unknown error'), 0, 2000),
        ])->save();
    }
}
