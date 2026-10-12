<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SyncBatchStatus;
use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\SyncBatchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Audit and idempotency record for one offline batch pushed by a device.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $user_id
 * @property int|null $personal_access_token_id
 * @property string $idempotency_key
 * @property string $payload_hash
 * @property string $device_id
 * @property string|null $device_platform
 * @property string|null $app_version
 * @property SyncBatchStatus $status
 * @property int $total_items
 * @property int $created_count
 * @property int $updated_count
 * @property int $skipped_count
 * @property int $failed_count
 * @property array<string, mixed>|null $result_summary
 * @property string|null $error_message
 * @property CarbonImmutable|null $client_sent_at
 * @property int|null $clock_skew_seconds
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $processing_started_at
 * @property CarbonImmutable|null $completed_at
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Organization $organization
 */
class SyncBatch extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<SyncBatchFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'idempotency_key',
        'payload_hash',
        'device_id',
        'device_platform',
        'app_version',
        'total_items',
        'client_sent_at',
        'received_at',
        'ip_address',
        'user_agent',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'received',
        'total_items' => 0,
        'created_count' => 0,
        'updated_count' => 0,
        'skipped_count' => 0,
        'failed_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'personal_access_token_id' => 'integer',
            'status' => SyncBatchStatus::class,
            'total_items' => 'integer',
            'created_count' => 'integer',
            'updated_count' => 'integer',
            'skipped_count' => 'integer',
            'failed_count' => 'integer',
            'result_summary' => 'array',
            'client_sent_at' => 'immutable_datetime',
            'clock_skew_seconds' => 'integer',
            'received_at' => 'immutable_datetime',
            'processing_started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(static function (SyncBatch $batch): void {
            if ($batch->getAttribute('received_at') === null) {
                $batch->received_at = CarbonImmutable::now();
            }

            if ($batch->client_sent_at !== null && $batch->clock_skew_seconds === null) {
                $batch->clock_skew_seconds = (int) $batch->client_sent_at->diffInSeconds($batch->received_at, false);
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<PersonalAccessToken, $this>
     */
    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }

    /**
     * @return HasMany<DamageAssessment, $this>
     */
    public function damageAssessments(): HasMany
    {
        return $this->hasMany(DamageAssessment::class);
    }

    /**
     * @return HasMany<Beneficiary, $this>
     */
    public function beneficiaries(): HasMany
    {
        return $this->hasMany(Beneficiary::class);
    }

    /**
     * @return HasMany<AssessmentImage, $this>
     */
    public function assessmentImages(): HasMany
    {
        return $this->hasMany(AssessmentImage::class);
    }

    /**
     * Whether the stored batch was produced from the same payload, i.e. a
     * safe retry rather than an idempotency-key collision.
     */
    public function matchesPayloadHash(string $payloadHash): bool
    {
        return hash_equals($this->payload_hash, strtolower($payloadHash));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForDevice(Builder $query, string $deviceId): Builder
    {
        return $query->where($this->qualifyColumn('device_id'), $deviceId);
    }

    /**
     * Batches stuck in a non-terminal state for longer than the given number of minutes.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeStale(Builder $query, int $minutes): Builder
    {
        return $query
            ->whereIn($this->qualifyColumn('status'), [SyncBatchStatus::Received->value, SyncBatchStatus::Processing->value])
            ->where($this->qualifyColumn('received_at'), '<', CarbonImmutable::now()->subMinutes($minutes));
    }
}
