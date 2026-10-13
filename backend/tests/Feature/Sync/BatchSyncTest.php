<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Enums\AssessmentStatus;
use App\Enums\ClientType;
use App\Enums\ImageSyncStatus;
use App\Enums\SyncBatchStatus;
use App\Models\AssessmentImage;
use App\Models\Beneficiary;
use App\Models\DamageAssessment;
use App\Models\Organization;
use App\Models\SyncBatch;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

final class BatchSyncTest extends SyncTestCase
{
    #[Test]
    public function a_batch_creates_linked_records_and_acknowledges_every_client_uuid(): void
    {
        $beneficiary = $this->beneficiaryItem();
        $assessment = $this->assessmentItem(['beneficiary_client_uuid' => $beneficiary['client_uuid']]);
        $image = $this->imageItem($assessment['client_uuid'], ['content_base64' => self::PNG_BASE64]);

        $response = $this->pushBatch($this->batch([
            'beneficiaries' => [$beneficiary],
            'assessments' => [$assessment],
            'images' => [$image],
        ]));

        $response->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'false')
            ->assertJsonPath('data.replayed', false)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.counts', ['total' => 3, 'created' => 3, 'updated' => 0, 'skipped' => 0, 'failed' => 0])
            ->assertJsonPath('data.results.beneficiaries.created', [$beneficiary['client_uuid']])
            ->assertJsonPath('data.results.assessments.created', [$assessment['client_uuid']])
            ->assertJsonPath('data.results.images.created', [$image['client_uuid']])
            ->assertJsonPath('data.images_awaiting_upload', []);

        self::assertEqualsCanonicalizing(
            [$beneficiary['client_uuid'], $assessment['client_uuid'], $image['client_uuid']],
            $response->json('data.acknowledged_client_uuids'),
        );

        $batch = SyncBatch::query()->sole();
        $storedAssessment = DamageAssessment::query()->sole();
        $storedBeneficiary = Beneficiary::query()->sole();

        self::assertSame($response->json('data.batch_id'), $batch->id);
        self::assertSame($storedAssessment->id, $response->json("data.id_map.assessments.{$assessment['client_uuid']}"));
        self::assertSame($storedBeneficiary->id, $storedAssessment->beneficiary_id);
        self::assertSame($this->agent->id, $storedAssessment->assessor_id);
        self::assertSame($this->agent->id, $storedBeneficiary->registered_by);
        self::assertSame($batch->id, $storedAssessment->sync_batch_id);
        self::assertSame($this->agent->organization_id, $storedAssessment->organization_id);
        self::assertSame(self::DEVICE_ID, $batch->device_id);
        self::assertSame($this->agent->tokens()->sole()->id, $batch->personal_access_token_id);
        self::assertSame([['category' => 'shelter', 'quantity' => 1], ['category' => 'water']], $storedAssessment->urgent_needs);
    }

    #[Test]
    public function stored_points_equal_st_setsrid_st_makepoint_of_lng_lat(): void
    {
        $assessment = $this->assessmentItem(['location' => ['lat' => 31.5017, 'lng' => 34.4668]]);

        $this->pushBatch($this->batch(['assessments' => [$assessment]]))->assertCreated();

        $equal = DB::selectOne(
            'SELECT ST_Equals(location::geometry, ST_SetSRID(ST_MakePoint(?, ?), 4326)) AS same FROM damage_assessments WHERE client_uuid = ?',
            [34.4668, 31.5017, $assessment['client_uuid']],
        );

        self::assertTrue((bool) $equal->same);
    }

    #[Test]
    public function retrying_the_same_batch_replays_the_original_result_without_duplicates(): void
    {
        $payload = $this->batch([
            'beneficiaries' => [$this->beneficiaryItem()],
            'assessments' => [$this->assessmentItem()],
        ]);

        $first = $this->pushBatch($payload)->assertCreated();
        $second = $this->pushBatch($payload)
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.replayed', true);

        self::assertSame($first->json('data.batch_id'), $second->json('data.batch_id'));
        self::assertSame($first->json('data.results'), $second->json('data.results'));
        self::assertSame($first->json('data.id_map'), $second->json('data.id_map'));
        self::assertSame(1, SyncBatch::query()->count());
        self::assertSame(1, DamageAssessment::query()->count());
        self::assertSame(1, Beneficiary::query()->count());
    }

    #[Test]
    public function reusing_an_idempotency_key_for_different_content_is_rejected(): void
    {
        $payload = $this->batch(['assessments' => [$this->assessmentItem()]]);
        $this->pushBatch($payload)->assertCreated();

        $tampered = $payload;
        $tampered['assessments'][0]['damage_tier'] = 'destroyed';

        $this->pushBatch($tampered)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'idempotency_key_reused');

        self::assertSame('severe', DamageAssessment::query()->sole()->damage_tier->value);
    }

    #[Test]
    public function a_retry_racing_an_in_flight_attempt_gets_409_with_retry_after(): void
    {
        $payload = $this->batch(['assessments' => [$this->assessmentItem()]]);
        $lock = Cache::lock("sync-batch:{$this->agent->organization_id}:{$payload['idempotency_key']}", 60);
        $lock->get();

        $this->pushBatch($payload)
            ->assertStatus(409)
            ->assertHeader('Retry-After', '5')
            ->assertJsonPath('code', 'sync_in_progress');

        $lock->release();
        $this->pushBatch($payload)->assertCreated();
    }

    #[Test]
    public function revisions_decide_between_update_unchanged_and_stale(): void
    {
        $assessment = $this->assessmentItem(['revision' => 2]);
        $this->pushBatch($this->batch(['assessments' => [$assessment]]))->assertCreated();

        $newer = [...$assessment, 'revision' => 3, 'damage_tier' => 'destroyed'];
        $this->pushBatch($this->batch(['assessments' => [$newer]]))
            ->assertJsonPath('data.results.assessments.updated', [$assessment['client_uuid']]);

        $same = [...$newer, 'damage_tier' => 'minor'];
        $this->pushBatch($this->batch(['assessments' => [$same]]))
            ->assertJsonPath('data.results.assessments.skipped.0.reason', 'unchanged');

        $older = [...$assessment, 'revision' => 1, 'damage_tier' => 'none'];
        $this->pushBatch($this->batch(['assessments' => [$older]]))
            ->assertJsonPath('data.results.assessments.skipped.0.reason', 'stale_revision')
            ->assertJsonPath('data.acknowledged_client_uuids', [$assessment['client_uuid']]);

        $stored = DamageAssessment::query()->sole();
        self::assertSame('destroyed', $stored->damage_tier->value);
        self::assertSame(3, $stored->revision);
    }

    #[Test]
    public function records_under_review_are_not_overwritten_by_devices(): void
    {
        $assessment = $this->assessmentItem();
        $this->pushBatch($this->batch(['assessments' => [$assessment]]))->assertCreated();
        DamageAssessment::query()->update(['status' => AssessmentStatus::UnderReview->value]);

        $this->pushBatch($this->batch(['assessments' => [[...$assessment, 'revision' => 5, 'damage_tier' => 'minor']]]))
            ->assertCreated()
            ->assertJsonPath('data.results.assessments.skipped.0.reason', 'locked_by_review');

        self::assertSame('severe', DamageAssessment::query()->sole()->damage_tier->value);
    }

    #[Test]
    public function an_unresolvable_reference_fails_only_that_item(): void
    {
        $orphan = $this->assessmentItem(['beneficiary_client_uuid' => (string) Str::uuid()]);
        $fine = $this->assessmentItem();

        $this->pushBatch($this->batch(['assessments' => [$orphan, $fine]]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'partially_failed')
            ->assertJsonPath('data.results.assessments.created', [$fine['client_uuid']])
            ->assertJsonPath('data.results.assessments.failed.0.client_uuid', $orphan['client_uuid'])
            ->assertJsonPath('data.results.assessments.failed.0.code', 'beneficiary_not_found')
            ->assertJsonPath('data.results.assessments.failed.0.retryable', true);

        self::assertSame(SyncBatchStatus::PartiallyFailed, SyncBatch::query()->sole()->status);
    }

    #[Test]
    public function references_resolve_to_records_synced_in_earlier_batches(): void
    {
        $beneficiary = $this->beneficiaryItem();
        $this->pushBatch($this->batch(['beneficiaries' => [$beneficiary]]))->assertCreated();

        $assessment = $this->assessmentItem(['beneficiary_client_uuid' => $beneficiary['client_uuid']]);
        $this->pushBatch($this->batch(['assessments' => [$assessment]]))
            ->assertJsonPath('data.results.assessments.created', [$assessment['client_uuid']]);

        self::assertSame(Beneficiary::query()->sole()->id, DamageAssessment::query()->sole()->beneficiary_id);
    }

    #[Test]
    public function a_national_id_already_registered_in_the_organization_is_reported_with_the_existing_record(): void
    {
        $existing = Beneficiary::factory()->for($this->agent->organization)->create();
        $duplicate = $this->beneficiaryItem(['national_id' => $existing->national_id]);

        $this->pushBatch($this->batch(['beneficiaries' => [$duplicate]]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.results.beneficiaries.failed.0.code', 'national_id_already_registered')
            ->assertJsonPath('data.results.beneficiaries.failed.0.retryable', false)
            ->assertJsonPath('data.results.beneficiaries.failed.0.context.existing_beneficiary_id', $existing->id);
    }

    #[Test]
    public function the_same_client_uuid_in_another_organization_never_collides(): void
    {
        $foreign = DamageAssessment::factory()->for(Organization::factory())->create(['damage_tier' => 'minor', 'revision' => 1]);
        $assessment = $this->assessmentItem(['client_uuid' => $foreign->client_uuid, 'revision' => 9, 'damage_tier' => 'destroyed']);

        $this->pushBatch($this->batch(['assessments' => [$assessment]]))
            ->assertJsonPath('data.results.assessments.created', [$foreign->client_uuid]);

        self::assertSame(2, DamageAssessment::query()->withoutGlobalScopes()->where('client_uuid', $foreign->client_uuid)->count());

        $foreign = DamageAssessment::query()->withoutGlobalScopes()->findOrFail($foreign->id);
        self::assertSame('minor', $foreign->damage_tier->value, 'The other organization\'s record must be untouched.');
        self::assertSame(1, $foreign->revision);
    }

    #[Test]
    public function inline_images_are_verified_stored_and_indexed(): void
    {
        $assessment = $this->assessmentItem();
        $image = $this->imageItem($assessment['client_uuid'], ['content_base64' => self::PNG_BASE64]);

        $this->pushBatch($this->batch(['assessments' => [$assessment], 'images' => [$image]]))->assertCreated();

        $stored = AssessmentImage::query()->sole();
        self::assertSame(ImageSyncStatus::Processed, $stored->sync_status);
        self::assertSame(1, $stored->width);
        self::assertSame(1, $stored->height);
        self::assertNotNull($stored->processed_at);
        Storage::disk('local')->assertExists((string) $stored->storage_path);
        self::assertSame($this->pngBytes(), Storage::disk('local')->get((string) $stored->storage_path));
    }

    #[Test]
    public function inline_content_that_does_not_match_its_hash_is_rejected_and_not_stored(): void
    {
        $assessment = $this->assessmentItem();
        $image = $this->imageItem($assessment['client_uuid'], [
            'content_base64' => self::PNG_BASE64,
            'sha256' => str_repeat('a', 64),
        ]);

        $this->pushBatch($this->batch(['assessments' => [$assessment], 'images' => [$image]]))
            ->assertCreated()
            ->assertJsonPath('data.results.images.failed.0.code', 'image_hash_mismatch')
            ->assertJsonPath('data.results.images.failed.0.retryable', false)
            ->assertJsonPath('data.results.assessments.created', [$assessment['client_uuid']]);

        self::assertSame(0, AssessmentImage::query()->count());
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    #[Test]
    public function a_re_declared_image_with_a_different_hash_is_refused(): void
    {
        $assessment = $this->assessmentItem();
        $image = $this->imageItem($assessment['client_uuid']);
        $this->pushBatch($this->batch(['assessments' => [$assessment], 'images' => [$image]]))->assertCreated();

        $this->pushBatch($this->batch(['images' => [[...$image, 'sha256' => str_repeat('b', 64)]]]))
            ->assertJsonPath('data.results.images.failed.0.code', 'image_immutable');
    }

    #[Test]
    public function large_clock_skew_is_corrected_and_future_timestamps_are_clamped(): void
    {
        // Device clock is two hours behind the server.
        $assessment = $this->assessmentItem(['assessed_at' => '2026-10-12T05:30:00+00:00']);
        $future = $this->assessmentItem(['assessed_at' => '2026-10-12T09:00:00+00:00']);

        $this->pushBatch($this->batch(
            ['assessments' => [$assessment, $future]],
            ['sent_at' => '2026-10-12T06:00:00+00:00'],
        ))
            ->assertCreated()
            ->assertJsonPath('data.clock.skew_seconds', 7200)
            ->assertJsonPath('data.clock.corrected', true)
            ->assertJsonPath('data.results.assessments.warnings.0.client_uuid', $future['client_uuid'])
            ->assertJsonPath('data.results.assessments.warnings.0.code', 'assessed_at_clamped');

        self::assertSame('2026-10-12 07:30:00', DamageAssessment::query()->where('client_uuid', $assessment['client_uuid'])->sole()->assessed_at->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-12 08:00:00', DamageAssessment::query()->where('client_uuid', $future['client_uuid'])->sole()->assessed_at->format('Y-m-d H:i:s'));
        self::assertSame(7200, SyncBatch::query()->sole()->clock_skew_seconds);
    }

    #[Test]
    public function timestamps_with_offsets_are_stored_in_utc(): void
    {
        $assessment = $this->assessmentItem(['assessed_at' => '2026-10-12T10:15:00+03:00']);

        $this->pushBatch($this->batch(['assessments' => [$assessment]], ['sent_at' => '2026-10-12T11:00:00+03:00']))
            ->assertJsonPath('data.clock.corrected', false);

        $raw = DB::selectOne("SELECT to_char(assessed_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS utc FROM damage_assessments");
        self::assertSame('2026-10-12 07:15:00', $raw->utc);
    }

    #[Test]
    public function a_field_token_may_only_push_for_its_own_device(): void
    {
        $this->pushBatch($this->batch(
            ['assessments' => [$this->assessmentItem()]],
            ['device' => ['id' => 'android-someone-elses-phone']],
        ))->assertForbidden();

        self::assertSame(0, SyncBatch::query()->count());
    }

    #[Test]
    public function each_section_requires_its_write_ability(): void
    {
        $contractor = User::factory()->contractor()->for($this->agent->organization)->create();
        $token = $this->issueToken($contractor, ClientType::FieldDevice, self::DEVICE_ID);

        $this->pushBatch($this->batch(['assessments' => [$this->assessmentItem()]]), $token)->assertForbidden();
    }

    #[Test]
    public function tokens_without_sync_push_are_rejected(): void
    {
        $donor = User::factory()->donor()->for($this->agent->organization)->create();

        $this->pushBatch($this->batch(['assessments' => [$this->assessmentItem()]]), $this->issueToken($donor, ClientType::WebDashboard))
            ->assertForbidden();
    }

    #[Test]
    public function malformed_batches_are_rejected_with_field_errors(): void
    {
        $this->pushBatch($this->batch([]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('batch');

        $this->pushBatch($this->batch(['beneficiaries' => [$this->beneficiaryItem(['national_id' => '123456789'])]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('beneficiaries.0.national_id');

        $this->pushBatch($this->batch(['assessments' => [$this->assessmentItem(['location' => ['lat' => 95, 'lng' => 34.4]])]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assessments.0.location.lat');

        $uuid = (string) Str::uuid();
        $this->pushBatch($this->batch(['assessments' => [$this->assessmentItem(['client_uuid' => $uuid]), $this->assessmentItem(['client_uuid' => $uuid])]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assessments.0.client_uuid');

        $this->pushBatch($this->batch(['assessments' => [$this->assessmentItem(['urgent_needs' => [['category' => 'water'], ['category' => 'water']]])]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assessments.0.urgent_needs');

        $this->pushBatch($this->batch(['beneficiaries' => [$this->beneficiaryItem(['household_size' => 3, 'children_count' => 2, 'elderly_count' => 2])]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('beneficiaries.0.household_size');

        self::assertSame(0, SyncBatch::query()->count());
    }

    #[Test]
    public function oversized_batches_must_be_split(): void
    {
        config(['rebuild.sync.max_items_per_batch' => 2]);

        $this->pushBatch($this->batch(['assessments' => [$this->assessmentItem(), $this->assessmentItem(), $this->assessmentItem()]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assessments', 'batch']);
    }
}
