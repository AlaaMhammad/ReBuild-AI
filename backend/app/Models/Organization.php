<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationType;
use Carbon\CarbonImmutable;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A tenant: an NGO, agency, authority, contractor firm or donor operating on the platform.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property OrganizationType $type
 * @property string|null $registration_number
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property array<string, mixed> $settings
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'type',
        'registration_number',
        'contact_email',
        'contact_phone',
        'settings',
        'is_active',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'settings' => '{}',
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => OrganizationType::class,
            'settings' => 'array',
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<Beneficiary, $this>
     */
    public function beneficiaries(): HasMany
    {
        return $this->hasMany(Beneficiary::class);
    }

    /**
     * @return HasMany<DamageAssessment, $this>
     */
    public function damageAssessments(): HasMany
    {
        return $this->hasMany(DamageAssessment::class);
    }

    /**
     * @return HasMany<AssessmentImage, $this>
     */
    public function assessmentImages(): HasMany
    {
        return $this->hasMany(AssessmentImage::class);
    }

    /**
     * @return HasMany<SyncBatch, $this>
     */
    public function syncBatches(): HasMany
    {
        return $this->hasMany(SyncBatch::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
