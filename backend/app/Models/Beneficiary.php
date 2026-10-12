<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DisplacementStatus;
use App\Enums\Gender;
use App\Enums\Governorate;
use App\Enums\VulnerabilityTag;
use App\Models\Concerns\BelongsToOrganization;
use App\Support\Identity\NationalId;
use App\Support\Identity\NationalIdHasher;
use App\Support\Text\ArabicNameNormalizer;
use Carbon\CarbonImmutable;
use Database\Factories\BeneficiaryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * A registered household, represented by its head of household.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $client_uuid
 * @property string|null $sync_batch_id
 * @property string|null $registered_by
 * @property string|null $national_id Decrypted, normalized 9-digit ID.
 * @property string|null $national_id_hash
 * @property string $full_name
 * @property string $full_name_normalized
 * @property Gender|null $gender
 * @property CarbonImmutable|null $date_of_birth
 * @property string|null $phone
 * @property Governorate|null $governorate
 * @property DisplacementStatus|null $displacement_status
 * @property string|null $address_description
 * @property int $household_size
 * @property int $children_count
 * @property int $elderly_count
 * @property int $disabled_count
 * @property int $pregnant_lactating_count
 * @property bool $is_female_headed
 * @property Collection<int, VulnerabilityTag> $vulnerability_tags
 * @property string|null $notes
 * @property int $revision
 * @property CarbonImmutable|null $client_created_at
 * @property CarbonImmutable|null $client_updated_at
 * @property CarbonImmutable|null $synced_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read string|null $masked_national_id
 * @property-read Organization $organization
 */
class Beneficiary extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<BeneficiaryFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'client_uuid',
        'national_id',
        'full_name',
        'gender',
        'date_of_birth',
        'phone',
        'governorate',
        'displacement_status',
        'address_description',
        'household_size',
        'children_count',
        'elderly_count',
        'disabled_count',
        'pregnant_lactating_count',
        'is_female_headed',
        'vulnerability_tags',
        'notes',
        'client_created_at',
        'client_updated_at',
    ];

    /**
     * PII and derived lookup keys never leave the model through serialization;
     * API resources expose masked_national_id unless the caller holds beneficiaries:pii.
     *
     * @var list<string>
     */
    protected $hidden = [
        'national_id',
        'national_id_hash',
        'full_name_normalized',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'household_size' => 1,
        'children_count' => 0,
        'elderly_count' => 0,
        'disabled_count' => 0,
        'pregnant_lactating_count' => 0,
        'is_female_headed' => false,
        'vulnerability_tags' => '[]',
        'revision' => 1,
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'national_id' => 'encrypted',
            'gender' => Gender::class,
            'date_of_birth' => 'immutable_date',
            'governorate' => Governorate::class,
            'displacement_status' => DisplacementStatus::class,
            'household_size' => 'integer',
            'children_count' => 'integer',
            'elderly_count' => 'integer',
            'disabled_count' => 'integer',
            'pregnant_lactating_count' => 'integer',
            'is_female_headed' => 'boolean',
            'vulnerability_tags' => AsEnumCollection::of(VulnerabilityTag::class),
            'revision' => 'integer',
            'client_created_at' => 'immutable_datetime',
            'client_updated_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(static function (Beneficiary $beneficiary): void {
            if ($beneficiary->isDirty('national_id')) {
                $nationalId = $beneficiary->national_id;

                if ($nationalId === null || trim($nationalId) === '') {
                    $beneficiary->national_id = null;
                    $beneficiary->national_id_hash = null;
                } else {
                    $normalized = NationalId::normalize($nationalId);
                    $beneficiary->national_id = $normalized;
                    $beneficiary->national_id_hash = app(NationalIdHasher::class)->hash($normalized);
                }
            }

            $fullName = $beneficiary->getAttribute('full_name');

            if (is_string($fullName) && ($beneficiary->isDirty('full_name') || $beneficiary->getAttribute('full_name_normalized') === null)) {
                $beneficiary->full_name = trim($fullName);
                $beneficiary->full_name_normalized = ArabicNameNormalizer::normalize($beneficiary->full_name);
            }
        });
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function maskedNationalId(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->national_id === null ? null : NationalId::mask($this->national_id));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    /**
     * @return BelongsTo<SyncBatch, $this>
     */
    public function syncBatch(): BelongsTo
    {
        return $this->belongsTo(SyncBatch::class);
    }

    /**
     * @return HasMany<DamageAssessment, $this>
     */
    public function damageAssessments(): HasMany
    {
        return $this->hasMany(DamageAssessment::class);
    }

    /**
     * Exact lookup by national ID through the keyed hash index; never decrypts rows.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWhereNationalId(Builder $query, string $nationalId): Builder
    {
        return $query->where(
            $this->qualifyColumn('national_id_hash'),
            app(NationalIdHasher::class)->hash($nationalId),
        );
    }

    /**
     * Households carrying any of the given vulnerability tags (served by the GIN index).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithAnyVulnerability(Builder $query, VulnerabilityTag ...$tags): Builder
    {
        if ($tags === []) {
            return $query;
        }

        // "??|" is PDO's escape for the jsonb "?|" (any key exists) operator.
        return $query->whereRaw(
            "{$query->getQuery()->getGrammar()->wrap($this->qualifyColumn('vulnerability_tags'))} ??| ?::text[]",
            ['{'.implode(',', array_map(static fn (VulnerabilityTag $tag): string => $tag->value, $tags)).'}'],
        );
    }

    /**
     * Trigram similarity search on the normalized name.
     *
     * The "%" operator lets PostgreSQL use the GIN trgm index (default
     * pg_trgm.similarity_threshold is 0.3); the explicit similarity() filter
     * then applies the caller's stricter threshold.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWhereNameSimilarTo(Builder $query, string $name, float $threshold = 0.4): Builder
    {
        if ($threshold < 0.3 || $threshold > 1.0) {
            throw new InvalidArgumentException('Name similarity threshold must be between 0.3 and 1.0.');
        }

        $column = $query->getQuery()->getGrammar()->wrap($this->qualifyColumn('full_name_normalized'));
        $normalized = ArabicNameNormalizer::normalize($name);

        return $query->whereRaw(
            "({$column} % ? AND similarity({$column}, ?) >= ?)",
            [$normalized, $normalized, $threshold],
        );
    }
}
