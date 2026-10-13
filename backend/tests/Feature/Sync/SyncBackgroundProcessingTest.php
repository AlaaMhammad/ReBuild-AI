<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Enums\ClientType;
use App\Enums\SyncBatchStatus;
use App\Jobs\ProcessAssessmentImageJob;
use App\Jobs\ProcessSyncBatchJob;
use App\Models\SyncBatch;
use App\Models\User;
use App\Services\Sync\AssessmentImageProcessor;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class SyncBackgroundProcessingTest extends SyncTestCase
{
    #[Test]
    public function the_batch_job_is_queued_on_the_sync_queue_after_commit(): void
    {
        Queue::fake();

        $batchId = $this->pushBatch($this->batch(['assessments' => [$this->assessmentItem()]]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'processing')
            ->json('data.batch_id');

        Queue::assertPushedOn('sync', ProcessSyncBatchJob::class, static fn (ProcessSyncBatchJob $job): bool => $job->syncBatchId === $batchId);
    }

    #[Test]
    public function replays_never_queue_a_second_job(): void
    {
        Queue::fake();
        $payload = $this->batch(['assessments' => [$this->assessmentItem()]]);

        $this->pushBatch($payload)->assertCreated();
        $this->pushBatch($payload)->assertOk();

        Queue::assertPushed(ProcessSyncBatchJob::class, 1);
    }

    #[Test]
    public function the_job_is_idempotent_on_terminal_batches(): void
    {
        $this->pushBatch($this->batch(['assessments' => [$this->assessmentItem()]]))->assertCreated();
        $batch = SyncBatch::query()->sole();
        $completedAt = $batch->completed_at;

        Carbon::setTestNow('2026-10-12 09:00:00');
        (new ProcessSyncBatchJob($batch->id))->handle(app(TenantContext::class), app(AssessmentImageProcessor::class));

        self::assertEquals($completedAt, $batch->refresh()->completed_at);
        self::assertSame(SyncBatchStatus::Completed, $batch->status);
    }

    #[Test]
    public function a_permanently_failing_job_records_the_error_without_losing_the_outcome(): void
    {
        Queue::fake();
        $this->pushBatch($this->batch(['assessments' => [$this->assessmentItem()]]))->assertCreated();
        $batch = SyncBatch::query()->sole();

        (new ProcessSyncBatchJob($batch->id))->failed(new RuntimeException('object storage unreachable'));

        $batch->refresh();
        self::assertSame(SyncBatchStatus::PartiallyFailed, $batch->status);
        self::assertSame('Post-processing failed: object storage unreachable', $batch->error_message);
        self::assertSame(1, $batch->created_count);
    }

    #[Test]
    public function stale_batches_and_images_are_re_dispatched_by_the_recovery_command(): void
    {
        // Simulate a lost worker: the job is queued but never runs, so the batch
        // stays "processing" and its inline image stays "uploaded".
        Queue::fake();
        $assessment = $this->assessmentItem();
        $this->pushBatch($this->batch([
            'assessments' => [$assessment],
            'images' => [$this->imageItem($assessment['client_uuid'], ['content_base64' => self::PNG_BASE64])],
        ]))->assertCreated();
        Queue::assertPushed(ProcessSyncBatchJob::class, 1);

        $this->artisan('rebuild:sync:recover-stale', ['--minutes' => 15])->assertSuccessful();
        Queue::assertPushed(ProcessSyncBatchJob::class, 1);
        Queue::assertNotPushed(ProcessAssessmentImageJob::class);

        Carbon::setTestNow(now()->addMinutes(16));
        $this->artisan('rebuild:sync:recover-stale', ['--minutes' => 15])->assertSuccessful();

        Queue::assertPushed(ProcessSyncBatchJob::class, 2);
        Queue::assertPushed(ProcessAssessmentImageJob::class, 1);
    }

    #[Test]
    public function batch_status_is_visible_to_its_sender_and_to_auditors_only(): void
    {
        $batchId = $this->pushBatch($this->batch(['assessments' => [$this->assessmentItem()]]))->json('data.batch_id');

        $colleague = User::factory()->fieldAgent()->for($this->agent->organization)->create();
        $this->forgetAuthenticatedUser();
        $this->withToken($this->issueToken($colleague, ClientType::FieldDevice, 'android-colleague-0001'))
            ->getJson("/api/v1/sync/batches/{$batchId}")
            ->assertNotFound();

        $coordinator = User::factory()->coordinator()->for($this->agent->organization)->create();
        $this->forgetAuthenticatedUser();
        $this->withToken($this->issueToken($coordinator, ClientType::WebDashboard))
            ->getJson("/api/v1/sync/batches/{$batchId}")
            ->assertOk()
            ->assertJsonPath('data.batch_id', $batchId);
    }
}
