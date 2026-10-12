<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\AsGeoPoint;
use App\Enums\ImageSyncStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasSpatialLocation;
use App\Support\Geo\GeoPoint;
use Carbon\CarbonImmutable;
use Database\Factories\AssessmentImageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $damage_assessment_id
 * @property string $client_uuid
 * @property string|null $sync_batch_id
 * @property string $disk
 * @property string|null $storage_path
 * @property string|null $original_filename
 * @property string|null $mime_type
 * @property int|null $size_bytes
 * @property string $sha256
 * @property int|null $width
 * @property int|null $height
 * @property GeoPoint|null $location
 * @property CarbonImmutable|null $captured_at
 * @property string|null $caption
 * @property ImageSyncStatus $sync_status
 * @property int $sync_attempts
 * @property string|null $last_sync_error
 * @property CarbonImmutable|null $uploaded_at
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read DamageAssessment $damageAssessment
 */
class AssessmentImage extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<AssessmentImageFactory> */
    use HasFactory;

    use HasSpatialLocation;
    use HasUuids;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'client_uuid',
        'original_filename',
        'mime_type',
        'size_bytes',
        'sha256',
        'width',
        'height',
        'location',
        'captured_at',
        'caption',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'disk',
        'storage_path',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'sync_status' => 'pending_upload',
        'sync_attempts' => 0,
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'location' => AsGeoPoint::class,
            'captured_at' => 'immutable_datetime',
            'sync_status' => ImageSyncStatus::class,
            'sync_attempts' => 'integer',
            'uploaded_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(static function (AssessmentImage $image): void {
            if ($image->getAttribute('disk') === null) {
                $image->disk = (string) config('rebuild.media.disk');
            }

            $image->sha256 = strtolower($image->sha256);
        });
    }

    /**
     * @return BelongsTo<DamageAssessment, $this>
     */
    public function damageAssessment(): BelongsTo
    {
        return $this->belongsTo(DamageAssessment::class);
    }

    /**
     * @return BelongsTo<SyncBatch, $this>
     */
    public function syncBatch(): BelongsTo
    {
        return $this->belongsTo(SyncBatch::class);
    }

    /**
     * Records that the binary has been stored and verified against the client-declared hash.
     */
    public function markUploaded(string $storagePath, int $sizeBytes, string $mimeType): void
    {
        $this->forceFill([
            'storage_path' => $storagePath,
            'size_bytes' => $sizeBytes,
            'mime_type' => $mimeType,
            'sync_status' => ImageSyncStatus::Uploaded,
            'uploaded_at' => CarbonImmutable::now(),
            'last_sync_error' => null,
        ])->save();
    }

    public function markFailed(string $reason): void
    {
        $this->forceFill([
            'sync_status' => ImageSyncStatus::Failed,
            'sync_attempts' => $this->sync_attempts + 1,
            'last_sync_error' => mb_substr($reason, 0, 2000),
        ])->save();
    }

    /**
     * Short-lived signed URL for the stored binary.
     */
    public function temporaryUrl(int $minutes = 10): string
    {
        if ($this->storage_path === null || ! $this->sync_status->hasStoredFile()) {
            throw new LogicException("Image [{$this->id}] has no stored file.");
        }

        return Storage::disk($this->disk)->temporaryUrl($this->storage_path, CarbonImmutable::now()->addMinutes($minutes));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAwaitingUpload(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('sync_status'), [
            ImageSyncStatus::PendingUpload->value,
            ImageSyncStatus::Failed->value,
        ]);
    }
}
