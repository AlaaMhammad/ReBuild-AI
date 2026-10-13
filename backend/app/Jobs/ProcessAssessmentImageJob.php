<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AssessmentImage;
use App\Services\Sync\AssessmentImageProcessor;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Indexes one image whose binary arrived through the dedicated content
 * endpoint (Horizon "media" queue).
 */
final class ProcessAssessmentImageJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 300;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly string $assessmentImageId,
    ) {
        $this->onQueue((string) config('rebuild.sync.media_queue'));
    }

    public function uniqueId(): string
    {
        return $this->assessmentImageId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [15, 60, 300, 900];
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['assessment-image:'.$this->assessmentImageId];
    }

    public function handle(TenantContext $tenant, AssessmentImageProcessor $processor): void
    {
        $image = AssessmentImage::query()->withoutGlobalScopes()->find($this->assessmentImageId);

        if ($image === null) {
            return;
        }

        $tenant->runAs($image->organization_id, static fn () => $processor->process($image));
    }
}
