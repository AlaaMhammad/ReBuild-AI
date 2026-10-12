<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Geo\GeoPoint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonException;

/**
 * Spatial query scopes for a geography(Point, 4326) column.
 *
 * All predicates are written so PostgreSQL can use the GiST index on the
 * column: ST_DWithin and && are index-assisted, and <-> performs an
 * index-driven KNN ordering. Distances on geography are metres on the
 * WGS84 spheroid.
 *
 * @mixin Model
 */
trait HasSpatialLocation
{
    /**
     * Hard upper bound for radius queries; anything wider should use a bounding box.
     */
    public const MAX_SPATIAL_RADIUS_METRES = 50_000.0;

    /**
     * Name of the geography column the scopes operate on.
     */
    public function spatialColumn(): string
    {
        return 'location';
    }

    /**
     * Records whose location lies within $metres of $center.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithinRadius(Builder $query, GeoPoint $center, float $metres): Builder
    {
        if (! is_finite($metres) || $metres <= 0.0 || $metres > self::MAX_SPATIAL_RADIUS_METRES) {
            throw new InvalidArgumentException(sprintf(
                'Radius must be greater than 0 and at most %.0f metres.',
                self::MAX_SPATIAL_RADIUS_METRES,
            ));
        }

        return $query->whereRaw(
            "ST_DWithin({$this->wrappedSpatialColumn($query)}, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)",
            [$center->longitude, $center->latitude, $metres],
        );
    }

    /**
     * Adds a "distance_m" (or custom alias) column with the distance in metres to $point.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithDistanceFrom(Builder $query, GeoPoint $point, string $alias = 'distance_m'): Builder
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $alias) !== 1) {
            throw new InvalidArgumentException("Invalid distance alias [{$alias}].");
        }

        if ($query->getQuery()->columns === null) {
            $query->select($this->qualifyColumn('*'));
        }

        return $query->selectRaw(
            "ST_Distance({$this->wrappedSpatialColumn($query)}, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) AS {$alias}",
            [$point->longitude, $point->latitude],
        );
    }

    /**
     * Orders by distance to $point using the index-assisted KNN operator.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOrderByDistance(Builder $query, GeoPoint $point, string $direction = 'asc'): Builder
    {
        $direction = strtolower($direction);

        if ($direction !== 'asc' && $direction !== 'desc') {
            throw new InvalidArgumentException("Order direction must be 'asc' or 'desc', got [{$direction}].");
        }

        return $query->orderByRaw(
            "{$this->wrappedSpatialColumn($query)} <-> ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography {$direction}",
            [$point->longitude, $point->latitude],
        );
    }

    /**
     * Records inside a map viewport. Bounding boxes must not cross the antimeridian.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithinBoundingBox(
        Builder $query,
        float $minLongitude,
        float $minLatitude,
        float $maxLongitude,
        float $maxLatitude,
    ): Builder {
        // Constructing GeoPoints validates coordinate ranges.
        $southWest = GeoPoint::fromLatLng($minLatitude, $minLongitude);
        $northEast = GeoPoint::fromLatLng($maxLatitude, $maxLongitude);

        if ($southWest->longitude >= $northEast->longitude || $southWest->latitude >= $northEast->latitude) {
            throw new InvalidArgumentException('Bounding box minimums must be strictly less than maximums.');
        }

        return $query->whereRaw(
            "{$this->wrappedSpatialColumn($query)} && ST_MakeEnvelope(?, ?, ?, ?, 4326)::geography",
            [$southWest->longitude, $southWest->latitude, $northEast->longitude, $northEast->latitude],
        );
    }

    /**
     * Records covered by a GeoJSON Polygon or MultiPolygon (e.g. a governorate or neighbourhood boundary).
     *
     * @param  Builder<static>  $query
     * @param  string|array<string, mixed>  $geoJson
     * @return Builder<static>
     */
    public function scopeWithinGeoJsonArea(Builder $query, string|array $geoJson): Builder
    {
        try {
            $geometry = is_string($geoJson)
                ? json_decode($geoJson, true, 64, JSON_THROW_ON_ERROR)
                : $geoJson;
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Area is not valid GeoJSON.', previous: $exception);
        }

        if (! is_array($geometry) || ! in_array($geometry['type'] ?? null, ['Polygon', 'MultiPolygon'], true)) {
            throw new InvalidArgumentException('Area must be a GeoJSON Polygon or MultiPolygon geometry.');
        }

        return $query->whereRaw(
            "ST_Covers(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)::geography, {$this->wrappedSpatialColumn($query)})",
            [json_encode($geometry, JSON_THROW_ON_ERROR)],
        );
    }

    /**
     * @param  Builder<static>  $query
     */
    private function wrappedSpatialColumn(Builder $query): string
    {
        return $query->getQuery()->getGrammar()->wrap($this->qualifyColumn($this->spatialColumn()));
    }
}
