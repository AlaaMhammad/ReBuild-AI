<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ClientType;
use App\Enums\TokenAbility;
use App\Enums\UserRole;
use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property UserRole $role
 * @property string $locale
 * @property bool $is_active
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property CarbonImmutable|null $last_login_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Organization $organization
 */
class User extends Authenticatable
{
    use BelongsToOrganization;

    /** @use HasApiTokens<PersonalAccessToken> */
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUuids;
    use Notifiable;
    use SoftDeletes;

    /**
     * Changes to these attributes invalidate every issued token, because token
     * abilities and tenant binding are derived from them at issuance.
     *
     * @var list<string>
     */
    public const TOKEN_INVALIDATING_ATTRIBUTES = ['role', 'is_active', 'password', 'email'];

    /**
     * organization_id and role are deliberately not mass assignable: tenant
     * membership is set from the tenant context and roles are granted explicitly.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'locale',
        'password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'locale' => 'ar',
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'email_verified_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'password' => 'hashed',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updated(static function (User $user): void {
            if ($user->wasChanged(self::TOKEN_INVALIDATING_ATTRIBUTES)) {
                $user->tokens()->delete();
            }
        });

        static::deleted(static function (User $user): void {
            $user->tokens()->delete();
        });
    }

    /**
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: static fn (string $value): string => mb_strtolower(trim($value)),
        );
    }

    /**
     * @return HasMany<DamageAssessment, $this>
     */
    public function assessments(): HasMany
    {
        return $this->hasMany(DamageAssessment::class, 'assessor_id');
    }

    /**
     * @return HasMany<Beneficiary, $this>
     */
    public function registeredBeneficiaries(): HasMany
    {
        return $this->hasMany(Beneficiary::class, 'registered_by');
    }

    /**
     * @return HasMany<SyncBatch, $this>
     */
    public function syncBatches(): HasMany
    {
        return $this->hasMany(SyncBatch::class);
    }

    public function hasRole(UserRole ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /**
     * Whether the current token carries the ability. Outside of token
     * authentication (e.g. console) this falls back to the role definition.
     */
    public function hasAbility(TokenAbility $ability): bool
    {
        $token = $this->currentAccessToken();

        if ($token !== null) {
            return $this->tokenCan($ability->value);
        }

        return in_array($ability, $this->role->abilities(), true);
    }

    public function canAuthenticateVia(ClientType $clientType): bool
    {
        return $this->is_active
            && $this->role->canUseClient($clientType)
            && $this->organization?->is_active === true;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_active'), true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithRole(Builder $query, UserRole ...$roles): Builder
    {
        return $query->whereIn(
            $this->qualifyColumn('role'),
            array_map(static fn (UserRole $role): string => $role->value, $roles),
        );
    }
}
