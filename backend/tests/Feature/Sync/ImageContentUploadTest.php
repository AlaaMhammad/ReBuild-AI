<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Enums\ImageSyncStatus;
use App\Models\AssessmentImage;
use App\Models\DamageAssessment;
use App\Models\Organization;
use App\Services\Sync\AssessmentImageProcessor;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;

final class ImageContentUploadTest extends SyncTestCase
{
    #[Test]
    public function metadata_first_then_binary_completes_the_two_phase_upload(): void
    {
        $assessment = $this->assessmentItem();
        $image = $this->imageItem($assessment['client_uuid']);

        $batchId = $this->pushBatch($this->batch(['assessments' => [$assessment], 'images' => [$image]]))
            ->assertCreated()
            ->assertJsonPath('data.images_awaiting_upload', [$image['client_uuid']])
            ->json('data.batch_id');

        self::assertSame(ImageSyncStatus::PendingUpload, AssessmentImage::query()->sole()->sync_status);

        $this->putContent($image['client_uuid'], $this->pngBytes())
            ->assertCreated()
            ->assertJsonPath('data.client_uuid', $image['client_uuid'])
            ->assertJsonPath('data.sync_status', 'processed');

        $stored = AssessmentImage::query()->sole();
        self::assertSame(ImageSyncStatus::Processed, $stored->sync_status);
        Storage::disk('local')->assertExists((string) $stored->storage_path);

        $this->forgetAuthenticatedUser();
        $this->withToken($this->token)->getJson("/api/v1/sync/batches/{$batchId}")
            ->assertOk()
            ->assertJsonPath('data.images_awaiting_upload', []);
    }

    #[Test]
    public function re_uploading_stored_content_is_an_idempotent_no_op(): void
    {
        $clientUuid = $this->declareImage();
        $this->putContent($clientUuid, $this->pngBytes())->assertCreated();

        $this->putContent($clientUuid, $this->pngBytes())->assertOk()->assertJsonPath('data.sync_status', 'processed');
        self::assertCount(1, Storage::disk('local')->allFiles());
    }

    #[Test]
    public function content_that_does_not_match_the_declared_hash_is_refused(): void
    {
        $clientUuid = $this->declareImage();

        $this->putContent($clientUuid, $this->pngBytes().'tampered')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'image_size_mismatch');

        self::assertSame(ImageSyncStatus::PendingUpload, AssessmentImage::query()->sole()->sync_status);
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    #[Test]
    public function non_image_content_is_refused_even_with_a_matching_hash(): void
    {
        $bytes = 'not an image at all';
        $clientUuid = $this->declareImage(['sha256' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes)]);

        $this->putContent($clientUuid, $bytes)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'unsupported_media_type');
    }

    #[Test]
    public function oversized_uploads_are_refused_before_reading(): void
    {
        config(['rebuild.media.max_image_bytes' => 10]);
        $clientUuid = $this->declareImage(['size_bytes' => 10]);

        $this->putContent($clientUuid, $this->pngBytes())->assertStatus(413);
    }

    #[Test]
    public function images_of_another_organization_are_not_reachable(): void
    {
        $foreign = AssessmentImage::factory()
            ->for(DamageAssessment::factory()->for(Organization::factory()))
            ->create();

        $this->putContent($foreign->client_uuid, $this->pngBytes())->assertNotFound();
    }

    #[Test]
    public function a_processed_image_whose_stored_file_was_corrupted_is_reset_for_re_upload(): void
    {
        $clientUuid = $this->declareImage();
        $this->putContent($clientUuid, $this->pngBytes())->assertCreated();
        $image = AssessmentImage::query()->sole();

        Storage::disk('local')->put((string) $image->storage_path, 'corrupted');
        $image->forceFill(['sync_status' => ImageSyncStatus::Uploaded])->save();

        app(AssessmentImageProcessor::class)->process($image);

        $image->refresh();
        self::assertSame(ImageSyncStatus::Failed, $image->sync_status);
        self::assertNull($image->storage_path);
        self::assertSame(1, $image->sync_attempts);

        $this->putContent($clientUuid, $this->pngBytes())->assertCreated()->assertJsonPath('data.sync_status', 'processed');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function declareImage(array $overrides = []): string
    {
        $assessment = $this->assessmentItem();
        $image = $this->imageItem($assessment['client_uuid'], $overrides);

        $this->pushBatch($this->batch(['assessments' => [$assessment], 'images' => [$image]]))->assertCreated();

        return $image['client_uuid'];
    }

    private function putContent(string $clientUuid, string $bytes): TestResponse
    {
        $this->forgetAuthenticatedUser();

        return $this->call(
            'PUT',
            "/api/v1/sync/images/{$clientUuid}/content",
            server: $this->transformHeadersToServerVars([
                'Authorization' => 'Bearer '.$this->token,
                'Content-Type' => 'image/png',
                'Content-Length' => (string) strlen($bytes),
                'Accept' => 'application/json',
            ]),
            content: $bytes,
        );
    }

    #[Test]
    public function unknown_images_return_404(): void
    {
        $this->putContent((string) Str::uuid(), $this->pngBytes())->assertNotFound();
    }
}
