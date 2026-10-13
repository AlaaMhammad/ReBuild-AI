<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ImageSyncStatus;
use App\Jobs\ProcessAssessmentImageJob;
use App\Jobs\ProcessSyncBatchJob;
use App\Models\AssessmentImage;
use App\Models\SyncBatch;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Re-dispatches post-processing that never finished: a worker crashed, Redis
 * lost the queue, or a deploy killed a job mid-flight. Both jobs are
 * idempotent and unique per record, so running this often is safe.
 */
final class RecoverStaleSyncWorkCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'rebuild:sync:recover-stale {--minutes=15 : Age after which unfinished work is considered stale}';

    /**
     * @var string
     */
    protected $description = 'Re-dispatch sync batches and images whose background processing never completed';

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $cutoff = CarbonImmutable::now()->subMinutes($minutes);

        $batches = 0;
        SyncBatch::query()
            ->withoutGlobalScopes()
            ->stale($minutes)
            ->select('id')
            ->orderBy('received_at')
            ->each(static function (SyncBatch $batch) use (&$batches): void {
                ProcessSyncBatchJob::dispatch($batch->id);
                $batches++;
            });

        $images = 0;
        AssessmentImage::query()
            ->withoutGlobalScopes()
            ->whereIn('sync_status', [ImageSyncStatus::Uploaded->value, ImageSyncStatus::Processing->value])
            ->where('updated_at', '<', $cutoff)
            ->select('id')
            ->orderBy('id')
            ->each(static function (AssessmentImage $image) use (&$images): void {
                ProcessAssessmentImageJob::dispatch($image->id);
                $images++;
            });

        $this->components->info("Re-dispatched {$batches} batch(es) and {$images} image(s) older than {$minutes} minute(s).");

        return self::SUCCESS;
    }
}
