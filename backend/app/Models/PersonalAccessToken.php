<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ClientType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum token bound to a client type and, for field devices, a device id.
 *
 * @property int $id
 * @property string $tokenable_type
 * @property string $tokenable_id
 * @property string $name
 * @property string $token
 * @property list<string>|null $abilities
 * @property ClientType $client_type
 * @property string|null $device_id
 * @property string|null $created_ip
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'token',
        'abilities',
        'client_type',
        'device_id',
        'created_ip',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'abilities' => 'json',
            'client_type' => ClientType::class,
            'last_used_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUnexpired(Builder $query): Builder
    {
        return $query->where(static function (Builder $query): void {
            $query->whereNull('expires_at')->orWhere('expires_at', '>', CarbonImmutable::now());
        });
    }
}
