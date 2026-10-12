<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Structural damage classification, aligned with UNOSAT / shelter-cluster tiers.
 */
enum DamageTier: string
{
    use HasValues;

    case None = 'none';
    case Minor = 'minor';
    case Moderate = 'moderate';
    case Severe = 'severe';
    case Destroyed = 'destroyed';

    /**
     * Ordinal severity from 0 (no damage) to 4 (destroyed), used for ordering and scoring.
     */
    public function severity(): int
    {
        return match ($this) {
            self::None => 0,
            self::Minor => 1,
            self::Moderate => 2,
            self::Severe => 3,
            self::Destroyed => 4,
        };
    }

    /**
     * @return list<self>
     */
    public static function atLeast(self $minimum): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $tier): bool => $tier->severity() >= $minimum->severity(),
        ));
    }
}
