<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\AsGeoPoint;
use App\Enums\AssessmentStatus;
use App\Enums\DamageTier;
use App\Enums\Governorate;
use App\Enums\HabitabilityStatus;
use App\Enums\NeedCategory;
use App\Enums\PriorityLevel;
use App\Enums\StructureType;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasSpatialLocation;
use App\Support\Geo\GeoPoint;
use Carbon\CarbonImmutable;
use Database\Factories\DamageAssessmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $client_uuid
 * @property string|null $sync_batch_id
 * @property string|null $beneficiary_id
 * @property string|null $assessor_id
 * @property GeoPoint $location
 * @property string|null $location_accuracy_m
 * @property Governorate|null $governorate
 * @property string|null $address_description
 * @property StructureType $structure_type
 * @property DamageTier $damage_tier
 * @property HabitabilityStatus $habitability
 * @property int|null $floors_count
 * @property int|null $units_count
 * @property list<array{category: string, quantity?: int, notes?: string}> $urgent_needs
 * @property string|null $notes
 * @property AssessmentStatus $status
 * @property string|null $priority_score
 * @property PriorityLevel|null $priority_level
 * @property CarbonImmutable|null $priority_calculated_at
 * @property string|null $duplicate_of_id
 * @property string|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property int $revision
 * @property CarbonImmutable $assessed_at
 * @property CarbonImmutable|null $client_created_at
 * @property CarbonImmutable|null $client_updated_at
 * @property CarbonImmutable|null $synced_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Organization $organization
 */
class DamageAssessment extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<DamageAssessmentFactory> */
    use HasFactory;

    use HasSpatialLocation;
    use HasUuids;
    use SoftDeletes;

    /**
     * Only attributes captured in the field are mass assignable. Triage,
     * review, deduplication and sync bookkeeping are set explicitly by
     * server-side services.
     *
     * @var list<string>
     */
    protected $fillable = [
        'client_uuid',
        'beneficiary_id',
        'location',
        'location_accuracy_m',
        'governorate',
        'address_description',
        'structure_type',
        'damage_tier',
        'habitability',
        'floors_count',
        'units_count',
        'urgent_needs',
        'notes',
        'assessed_at',
        'client_created_at',
        'client_updated_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'urgent_needs' => '[]',
        'status' => 'submitted',
        'revision' => 1,
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'location' => AsGeoPoint::class,
            'location_accuracy_m' => 'decimal:2',
            'governorate' => Governorate::class,
            'structure_type' => StructureType::class,
            'damage_tier' => DamageTier::class,
            'habitability' => HabitabilityStatus::class,
            'floors_count' => 'integer',
            'units_count' => 'integer',
            'urgent_needs' => 'array',
            'status' => AssessmentStatus::class,
            'priority_score' => 'decimal:2',
            'priority_level' => PriorityLevel::class,
            'priority_calculated_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'revision' => 'integer',
            'assessed_at' => 'immutable_datetime',
            'client_created_at' => 'immutable_datetime',
            'client_updated_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Beneficiary, $this>
     */
    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessor_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return BelongsTo<SyncBatch, $this>
     */
    public function syncBatch(): BelongsTo
    {
        return $this->belongsTo(SyncBatch::class);
    }

    /**
     * The canonical assessment this record was flagged as a duplicate of.
     *
     * @return BelongsTo<DamageAssessment, $this>
     */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }

    /**
     * @return HasMany<DamageAssessment, $this>
     */
    public function duplicates(): HasMany
    {
        return $this->hasMany(self::class, 'duplicate_of_id');
    }

    /**
     * @return HasMany<AssessmentImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(AssessmentImage::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithStatus(Builder $query, AssessmentStatus ...$statuses): Builder
    {
        return $query->whereIn(
            $this->qualifyColumn('status'),
            array_map(static fn (AssessmentStatus $status): string => $status->value, $statuses),
        );
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithMinimumDamage(Builder $query, DamageTier $minimum): Builder
    {
        return $query->whereIn(
            $this->qualifyColumn('damage_tier'),
            array_map(static fn (DamageTier $tier): string => $tier->value, DamageTier::atLeast($minimum)),
        );
    }

    /**
     * Highest priority first; unscored records last.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrderByPriority(Builder $query): Builder
    {
        return $query->orderByRaw(
            "{$query->getQuery()->getGrammar()->wrap($this->qualifyColumn('priority_score'))} DESC NULLS LAST"
        );
    }

    /**
     * Assessments whose urgent_needs include an entry with the given category.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNeedingCategory(Builder $query, NeedCategory $category): Builder
    {
        return $query->whereRaw(
            "{$query->getQuery()->getGrammar()->wrap($this->qualifyColumn('urgent_needs'))} @> ?::jsonb",
            [json_encode([['category' => $category->value]], JSON_THROW_ON_ERROR)],
        );
    }
}
