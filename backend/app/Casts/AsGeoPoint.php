<?php

declare(strict_types=1);

namespace App\Casts;

use App\Support\Geo\GeoPoint;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\ComparesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts a PostGIS geography(Point, 4326) column to a GeoPoint value object.
 *
 * Writes are emitted as EWKT strings; PostgreSQL resolves the untyped bound
 * parameter to the column's geography type, so no raw expressions are needed
 * and the value remains a normal prepared-statement binding.
 *
 * Dirty checking compares points semantically: the database returns hex EWKB
 * while the cast writes EWKT, so a raw string comparison would mark every
 * loaded-and-accessed location as modified.
 *
 * @implements CastsAttributes<GeoPoint|null, GeoPoint|array<string, mixed>|string|null>
 */
final class AsGeoPoint implements CastsAttributes, ComparesCastableAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?GeoPoint
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof GeoPoint) {
            return $value;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("Attribute [{$key}] contains an unsupported spatial value.");
        }

        return GeoPoint::parse($value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $point = match (true) {
            $value === null => null,
            $value instanceof GeoPoint => $value,
            is_array($value) => GeoPoint::fromArray($value),
            is_string($value) => GeoPoint::parse($value),
            default => throw new InvalidArgumentException(
                "Attribute [{$key}] must be a GeoPoint, a lat/lng array, an (E)WKT/WKB string, or null."
            ),
        };

        return [$key => $point?->toEwkt()];
    }

    public function compare(Model $model, string $key, mixed $firstValue, mixed $secondValue): bool
    {
        $first = $this->get($model, $key, $firstValue, []);
        $second = $this->get($model, $key, $secondValue, []);

        if ($first === null || $second === null) {
            return $first === $second;
        }

        return $first->equals($second);
    }
}
