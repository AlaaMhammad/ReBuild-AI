<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Models\AssessmentImage;
use App\Models\Beneficiary;
use App\Models\DamageAssessment;
use App\Models\SyncBatch;
use App\Models\User;
use App\Support\Geo\GeoPoint;
use App\Support\Identity\NationalIdHasher;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * Upserts the records of one validated batch.
 *
 * Must be called inside a database transaction (SyncBatchService owns it).
 * Every item is written inside its own savepoint, so one rejected item never
 * aborts the batch: it is reported as failed and the rest commit together
 * with the sync_batches row.
 *
 * Processing order is beneficiaries → assessments → images, so that records
 * captured together offline can reference each other by client_uuid within a
 * single batch.
 *
 * Point formatting: locations are bound as EWKT "SRID=4326;POINT(lng lat)",
 * which PostgreSQL coerces to exactly the geography value produced by
 * ST_SetSRID(ST_MakePoint(lng, lat), 4326) (asserted by the test suite).
 */
final class SyncBatchIngestor
{
    private const BENEFICIARY_FIELDS = [
        'national_id', 'full_name', 'gender', 'date_of_birth', 'phone', 'governorate',
        'displacement_status', 'address_description', 'household_size', 'children_count',
        'elderly_count', 'disabled_count', 'pregnant_lactating_count', 'is_female_headed',
        'vulnerability_tags', 'notes',
    ];

    private const ASSESSMENT_FIELDS = [
        'governorate', 'address_description', 'structure_type', 'damage_tier', 'habitability',
        'floors_count', 'units_count', 'notes',
    ];

    /**
     * @var list<array{disk: string, path: string}>
     */
    private array $writtenFiles = [];

    public function __construct(
        private readonly ImageBinaryStore $binaries,
        private readonly NationalIdHasher $nationalIdHasher,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  Validated BatchSyncRequest data
     */
    public function ingest(SyncBatch $batch, User $user, array $payload, DeviceClock $clock): BatchOutcome
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('SyncBatchIngestor::ingest() must run inside a database transaction.');
        }

        $this->writtenFiles = [];
        $outcome = new BatchOutcome;

        $this->ingestBeneficiaries($batch, $user, $this->items($payload, 'beneficiaries'), $clock, $outcome);
        $this->ingestAssessments($batch, $user, $this->items($payload, 'assessments'), $clock, $outcome);
        $this->ingestImages($batch, $this->items($payload, 'images'), $clock, $outcome);

        return $outcome;
    }

    /**
     * Files written during the last ingest(); the caller deletes them if the
     * surrounding transaction rolls back.
     *
     * @return list<array{disk: string, path: string}>
     */
    public function writtenFiles(): array
    {
        return $this->writtenFiles;
    }

    // ---------------------------------------------------------------------
    // Beneficiaries
    // ---------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function ingestBeneficiaries(SyncBatch $batch, User $user, array $items, DeviceClock $clock, BatchOutcome $outcome): void
    {
        if ($items === []) {
            return;
        }

        /** @var Collection<string, Beneficiary> $existing */
        $existing = Beneficiary::query()
            ->forOrganization($batch->organization_id)
            ->withTrashed()
            ->whereIn('client_uuid', array_column($items, 'client_uuid'))
            ->lockForUpdate()
            ->get()
            ->keyBy('client_uuid');

        foreach ($items as $item) {
            $clientUuid = (string) $item['client_uuid'];
            $revision = (int) $item['revision'];
            $current = $existing->get($clientUuid);

            if ($current !== null && ($skip = $this->revisionSkip($current, $revision)) !== null) {
                $outcome->skipped(BatchOutcome::BENEFICIARIES, $clientUuid, $skip, $current->id);

                continue;
            }

            if (filled($item['national_id'] ?? null)) {
                $conflict = Beneficiary::query()
                    ->forOrganization($batch->organization_id)
                    ->where('national_id_hash', $this->nationalIdHasher->hash((string) $item['national_id']))
                    ->when($current !== null, static fn ($query) => $query->whereKeyNot($current->id))
                    ->first(['id', 'client_uuid']);

                if ($conflict !== null) {
                    $outcome->failed(
                        BatchOutcome::BENEFICIARIES,
                        $clientUuid,
                        FailureCode::NationalIdAlreadyRegistered,
                        'This national ID is already registered to another household of your organization.',
                        ['existing_beneficiary_id' => $conflict->id, 'existing_client_uuid' => $conflict->client_uuid],
                    );

                    continue;
                }
            }

            $saved = $this->attempt($outcome, BatchOutcome::BENEFICIARIES, $clientUuid, function () use ($batch, $user, $item, $current, $clientUuid, $revision, $clock, $outcome): Beneficiary {
                $beneficiary = $current ?? new Beneficiary;
                $beneficiary->fill(array_intersect_key($item, array_flip(self::BENEFICIARY_FIELDS)));
                $this->applyClientTimestamps($beneficiary, $item, $clock, $outcome, BatchOutcome::BENEFICIARIES, $clientUuid);

                if (! $beneficiary->exists) {
                    $beneficiary->organization_id = $batch->organization_id;
                    $beneficiary->client_uuid = $clientUuid;
                    $beneficiary->registered_by = $user->id;
                }

                $beneficiary->revision = $revision;
                $beneficiary->sync_batch_id = $batch->id;
                $beneficiary->synced_at = CarbonImmutable::now();
                $beneficiary->save();

                return $beneficiary;
            });

            $this->recordWrite($outcome, BatchOutcome::BENEFICIARIES, $clientUuid, $saved, $current === null);
        }
    }

    // ---------------------------------------------------------------------
    // Damage assessments
    // ---------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function ingestAssessments(SyncBatch $batch, User $user, array $items, DeviceClock $clock, BatchOutcome $outcome): void
    {
        if ($items === []) {
            return;
        }

        /** @var Collection<string, DamageAssessment> $existing */
        $existing = DamageAssessment::query()
            ->forOrganization($batch->organization_id)
            ->withTrashed()
            ->whereIn('client_uuid', array_column($items, 'client_uuid'))
            ->lockForUpdate()
            ->get()
            ->keyBy('client_uuid');

        $beneficiaryIds = $this->resolveReferences(
            Beneficiary::class,
            $batch->organization_id,
            array_column($items, 'beneficiary_client_uuid'),
            BatchOutcome::BENEFICIARIES,
            $outcome,
        );

        foreach ($items as $item) {
            $clientUuid = (string) $item['client_uuid'];
            $revision = (int) $item['revision'];
            $current = $existing->get($clientUuid);
            $beneficiaryReference = $item['beneficiary_client_uuid'] ?? null;
            $beneficiaryId = null;

            if ($beneficiaryReference !== null) {
                $beneficiaryId = $outcome->hasFailed(BatchOutcome::BENEFICIARIES, (string) $beneficiaryReference)
                    ? null
                    : ($beneficiaryIds[$beneficiaryReference] ?? null);

                if ($beneficiaryId === null) {
                    $outcome->failed(
                        BatchOutcome::ASSESSMENTS,
                        $clientUuid,
                        FailureCode::BeneficiaryNotFound,
                        'The referenced beneficiary has not been synced; resend after it is acknowledged.',
                        ['beneficiary_client_uuid' => $beneficiaryReference],
                    );

                    continue;
                }
            }

            if ($current !== null && ! $current->trashed() && ! $current->status->isEditableFromDevice()) {
                $outcome->skipped(BatchOutcome::ASSESSMENTS, $clientUuid, SkipReason::LockedByReview, $current->id);

                continue;
            }

            if ($current !== null && ($skip = $this->revisionSkip($current, $revision)) !== null) {
                $outcome->skipped(BatchOutcome::ASSESSMENTS, $clientUuid, $skip, $current->id);

                continue;
            }

            $saved = $this->attempt($outcome, BatchOutcome::ASSESSMENTS, $clientUuid, function () use ($batch, $user, $item, $current, $clientUuid, $revision, $beneficiaryId, $clock, $outcome): DamageAssessment {
                $assessment = $current ?? new DamageAssessment;
                $assessment->fill(array_intersect_key($item, array_flip(self::ASSESSMENT_FIELDS)));

                $assessment->location = GeoPoint::fromLatLng((float) $item['location']['lat'], (float) $item['location']['lng']);
                $assessment->location_accuracy_m = isset($item['location']['accuracy_m']) ? (string) $item['location']['accuracy_m'] : null;
                $assessment->beneficiary_id = $beneficiaryId;

                if (array_key_exists('urgent_needs', $item)) {
                    $assessment->urgent_needs = $this->normalizeUrgentNeeds((array) $item['urgent_needs']);
                }

                $assessedAt = $clock->toServerTime((string) $item['assessed_at']);
                $assessment->assessed_at = $assessedAt['value'];

                if ($assessedAt['clamped']) {
                    $outcome->warning(BatchOutcome::ASSESSMENTS, $clientUuid, 'assessed_at_clamped', 'assessed_at was in the future and has been set to the server receive time.');
                }

                $this->applyClientTimestamps($assessment, $item, $clock, $outcome, BatchOutcome::ASSESSMENTS, $clientUuid);

                if (! $assessment->exists) {
                    $assessment->organization_id = $batch->organization_id;
                    $assessment->client_uuid = $clientUuid;
                    $assessment->assessor_id = $user->id;
                }

                $assessment->revision = $revision;
                $assessment->sync_batch_id = $batch->id;
                $assessment->synced_at = CarbonImmutable::now();
                $assessment->save();

                return $assessment;
            });

            $this->recordWrite($outcome, BatchOutcome::ASSESSMENTS, $clientUuid, $saved, $current === null);
        }
    }

    // ---------------------------------------------------------------------
    // Images
    // ---------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function ingestImages(SyncBatch $batch, array $items, DeviceClock $clock, BatchOutcome $outcome): void
    {
        if ($items === []) {
            return;
        }

        /** @var Collection<string, AssessmentImage> $existing */
        $existing = AssessmentImage::query()
            ->forOrganization($batch->organization_id)
            ->withTrashed()
            ->whereIn('client_uuid', array_column($items, 'client_uuid'))
            ->lockForUpdate()
            ->get()
            ->keyBy('client_uuid');

        $assessmentIds = $this->resolveReferences(
            DamageAssessment::class,
            $batch->organization_id,
            array_column($items, 'assessment_client_uuid'),
            BatchOutcome::ASSESSMENTS,
            $outcome,
        );

        foreach ($items as $item) {
            $clientUuid = (string) $item['client_uuid'];
            $sha256 = strtolower((string) $item['sha256']);
            $assessmentReference = (string) $item['assessment_client_uuid'];
            $content = filled($item['content_base64'] ?? null) ? (string) $item['content_base64'] : null;
            $current = $existing->get($clientUuid);

            $assessmentId = $outcome->hasFailed(BatchOutcome::ASSESSMENTS, $assessmentReference)
                ? null
                : ($assessmentIds[$assessmentReference] ?? null);

            if ($assessmentId === null) {
                $outcome->failed(
                    BatchOutcome::IMAGES,
                    $clientUuid,
                    FailureCode::AssessmentNotFound,
                    'The referenced assessment has not been synced; resend after it is acknowledged.',
                    ['assessment_client_uuid' => $assessmentReference],
                );

                continue;
            }

            if ($current !== null) {
                $this->ingestExistingImage($current, $clientUuid, $sha256, $assessmentId, $content, $outcome);

                continue;
            }

            $saved = $this->attempt($outcome, BatchOutcome::IMAGES, $clientUuid, function () use ($batch, $item, $clientUuid, $sha256, $assessmentId, $content, $clock, $outcome): AssessmentImage {
                $image = new AssessmentImage([
                    'client_uuid' => $clientUuid,
                    'original_filename' => $item['original_filename'] ?? null,
                    'mime_type' => $item['mime_type'],
                    'size_bytes' => (int) $item['size_bytes'],
                    'sha256' => $sha256,
                    'width' => $item['width'] ?? null,
                    'height' => $item['height'] ?? null,
                    'location' => isset($item['location']) ? GeoPoint::fromArray((array) $item['location']) : null,
                    'caption' => $item['caption'] ?? null,
                ]);

                $capturedAt = $clock->toServerTime(isset($item['captured_at']) ? (string) $item['captured_at'] : null);
                $image->captured_at = $capturedAt['value'];

                if ($capturedAt['clamped']) {
                    $outcome->warning(BatchOutcome::IMAGES, $clientUuid, 'captured_at_clamped', 'captured_at was in the future and has been set to the server receive time.');
                }

                $image->organization_id = $batch->organization_id;
                $image->damage_assessment_id = $assessmentId;
                $image->sync_batch_id = $batch->id;
                $image->save();

                if ($content !== null) {
                    $this->storeBinary($image, $content);
                }

                return $image;
            });

            $this->recordWrite($outcome, BatchOutcome::IMAGES, $clientUuid, $saved, true);

            if ($saved instanceof AssessmentImage && ! $saved->sync_status->hasStoredFile()) {
                $outcome->awaitingUpload($clientUuid);
            }
        }
    }

    /**
     * Images are immutable once declared: a re-sent client_uuid may only
     * deliver the binary that was promised (same hash, same assessment).
     */
    private function ingestExistingImage(
        AssessmentImage $current,
        string $clientUuid,
        string $sha256,
        string $assessmentId,
        ?string $content,
        BatchOutcome $outcome,
    ): void {
        if ($current->trashed()) {
            $outcome->skipped(BatchOutcome::IMAGES, $clientUuid, SkipReason::DeletedOnServer, $current->id);

            return;
        }

        if ($current->sha256 !== $sha256 || $current->damage_assessment_id !== $assessmentId) {
            $outcome->failed(
                BatchOutcome::IMAGES,
                $clientUuid,
                FailureCode::ImageImmutable,
                'This image was already declared with a different hash or assessment; capture it under a new client_uuid.',
            );

            return;
        }

        $awaiting = ! $current->sync_status->hasStoredFile();

        if ($content === null || ! $awaiting) {
            $outcome->skipped(BatchOutcome::IMAGES, $clientUuid, SkipReason::Unchanged, $current->id);

            if ($awaiting) {
                $outcome->awaitingUpload($clientUuid);
            }

            return;
        }

        $saved = $this->attempt($outcome, BatchOutcome::IMAGES, $clientUuid, function () use ($current, $content): AssessmentImage {
            $this->storeBinary($current, $content);

            return $current;
        });

        $this->recordWrite($outcome, BatchOutcome::IMAGES, $clientUuid, $saved, false);
    }

    private function storeBinary(AssessmentImage $image, string $base64): void
    {
        $bytes = $this->binaries->decodeBase64($base64);
        $path = $this->binaries->store($image, $bytes);

        try {
            $image->markUploaded($path, strlen($bytes), (string) $image->mime_type);
        } catch (Throwable $exception) {
            $this->binaries->delete($image->disk, $path);

            throw $exception;
        }

        $this->writtenFiles[] = ['disk' => $image->disk, 'path' => $path];
    }

    // ---------------------------------------------------------------------
    // Shared helpers
    // ---------------------------------------------------------------------

    /**
     * Runs one item's write in a savepoint and converts expected per-item
     * failures into outcome entries. Unexpected errors propagate and abort
     * the whole batch (the device retries it with the same idempotency key).
     *
     * @template TModel of Model
     *
     * @param  Closure(): TModel  $write
     * @return TModel|null
     */
    private function attempt(BatchOutcome $outcome, string $entity, string $clientUuid, Closure $write): ?Model
    {
        try {
            return DB::transaction($write);
        } catch (ItemRejectedException $exception) {
            $outcome->failed($entity, $clientUuid, $exception->failureCode, $exception->getMessage());
        } catch (UniqueConstraintViolationException) {
            $outcome->failed($entity, $clientUuid, FailureCode::ConcurrentWrite, 'Another submission wrote this record at the same time; resend it.');
        } catch (QueryException $exception) {
            if (! str_starts_with((string) $exception->getCode(), '23')) {
                throw $exception;
            }

            $constraint = preg_match('/constraint "([^"]+)"/', $exception->getMessage(), $matches) === 1 ? $matches[1] : 'unknown';

            $outcome->failed($entity, $clientUuid, FailureCode::ConstraintViolation, "Rejected by data integrity rule [{$constraint}].", ['constraint' => $constraint]);
        }

        return null;
    }

    private function recordWrite(BatchOutcome $outcome, string $entity, string $clientUuid, ?Model $saved, bool $isNew): void
    {
        if ($saved === null) {
            return;
        }

        $isNew
            ? $outcome->created($entity, $clientUuid, (string) $saved->getKey())
            : $outcome->updated($entity, $clientUuid, (string) $saved->getKey());
    }

    private function revisionSkip(Beneficiary|DamageAssessment $current, int $revision): ?SkipReason
    {
        return match (true) {
            $current->trashed() => SkipReason::DeletedOnServer,
            $revision < $current->revision => SkipReason::StaleRevision,
            $revision === $current->revision => SkipReason::Unchanged,
            default => null,
        };
    }

    /**
     * Maps client_uuid references to server ids: first from records written
     * earlier in this batch, then from the database (active records only).
     *
     * @param  class-string<Beneficiary|DamageAssessment>  $model
     * @param  array<int, mixed>  $references
     * @return array<string, string>
     */
    private function resolveReferences(string $model, string $organizationId, array $references, string $entity, BatchOutcome $outcome): array
    {
        $references = array_values(array_unique(array_filter(array_map(
            static fn (mixed $reference): ?string => is_string($reference) && $reference !== '' ? $reference : null,
            $references,
        ))));

        $resolved = [];
        $unresolved = [];

        foreach ($references as $reference) {
            $serverId = $outcome->serverIdFor($entity, $reference);

            if ($serverId !== null) {
                $resolved[$reference] = $serverId;
            } else {
                $unresolved[] = $reference;
            }
        }

        if ($unresolved !== []) {
            /** @var array<string, string> $fromDatabase */
            $fromDatabase = $model::query()
                ->forOrganization($organizationId)
                ->whereIn('client_uuid', $unresolved)
                ->pluck('id', 'client_uuid')
                ->all();

            $resolved += $fromDatabase;
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function applyClientTimestamps(Model $model, array $item, DeviceClock $clock, BatchOutcome $outcome, string $entity, string $clientUuid): void
    {
        foreach (['client_created_at', 'client_updated_at'] as $field) {
            if (! array_key_exists($field, $item)) {
                continue;
            }

            $converted = $clock->toServerTime($item[$field] === null ? null : (string) $item[$field]);
            $model->setAttribute($field, $converted['value']);

            if ($converted['clamped']) {
                $outcome->warning($entity, $clientUuid, "{$field}_clamped", "{$field} was in the future and has been set to the server receive time.");
            }
        }
    }

    /**
     * @param  array<int, mixed>  $needs
     * @return list<array{category: string, quantity?: int, notes?: string}>
     */
    private function normalizeUrgentNeeds(array $needs): array
    {
        $normalized = [];

        foreach ($needs as $need) {
            if (! is_array($need) || ! isset($need['category'])) {
                continue;
            }

            $entry = ['category' => (string) $need['category']];

            if (isset($need['quantity'])) {
                $entry['quantity'] = (int) $need['quantity'];
            }

            if (filled($need['notes'] ?? null)) {
                $entry['notes'] = (string) $need['notes'];
            }

            $normalized[] = $entry;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function items(array $payload, string $section): array
    {
        return array_values(array_filter((array) ($payload[$section] ?? []), 'is_array'));
    }
}
