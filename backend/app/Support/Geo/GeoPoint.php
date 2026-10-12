<?php

declare(strict_types=1);

namespace App\Support\Geo;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Immutable WGS84 (SRID 4326) point.
 *
 * Reads PostGIS output (hex-encoded EWKB / ISO WKB) and writes EWKT, which
 * PostgreSQL coerces into geography(Point, 4326) on insert/update.
 */
final class GeoPoint implements JsonSerializable
{
    public const SRID = 4326;

    private const WKB_POINT = 1;

    private const EWKB_FLAG_Z = 0x80000000;

    private const EWKB_FLAG_M = 0x40000000;

    private const EWKB_FLAG_SRID = 0x20000000;

    public function __construct(
        public readonly float $latitude,
        public readonly float $longitude,
    ) {
        if (! is_finite($latitude) || $latitude < -90.0 || $latitude > 90.0) {
            throw new InvalidArgumentException("Latitude must be a finite number between -90 and 90, got [{$latitude}].");
        }

        if (! is_finite($longitude) || $longitude < -180.0 || $longitude > 180.0) {
            throw new InvalidArgumentException("Longitude must be a finite number between -180 and 180, got [{$longitude}].");
        }
    }

    public static function fromLatLng(float $latitude, float $longitude): self
    {
        return new self($latitude, $longitude);
    }

    /**
     * Accepts ['lat' => .., 'lng' => ..] or ['latitude' => .., 'longitude' => ..].
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $latitude = $data['lat'] ?? $data['latitude'] ?? null;
        $longitude = $data['lng'] ?? $data['longitude'] ?? null;

        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            throw new InvalidArgumentException('A point requires numeric "lat"/"lng" (or "latitude"/"longitude") keys.');
        }

        return new self((float) $latitude, (float) $longitude);
    }

    /**
     * Parses "SRID=4326;POINT(lng lat)" or "POINT(lng lat)".
     */
    public static function fromEwkt(string $ewkt): self
    {
        $pattern = '/^\s*(?:SRID=(\d+)\s*;\s*)?POINT\s*\(\s*([-+]?[0-9]*\.?[0-9]+(?:[eE][-+]?[0-9]+)?)\s+([-+]?[0-9]*\.?[0-9]+(?:[eE][-+]?[0-9]+)?)\s*\)\s*$/i';

        if (preg_match($pattern, $ewkt, $matches) !== 1) {
            throw new InvalidArgumentException('Value is not a valid 2D (E)WKT point.');
        }

        if ($matches[1] !== '' && (int) $matches[1] !== self::SRID) {
            throw new InvalidArgumentException("Unsupported SRID [{$matches[1]}]; expected ".self::SRID.'.');
        }

        return new self((float) $matches[3], (float) $matches[2]);
    }

    /**
     * Parses a hex-encoded EWKB or ISO WKB point, as returned by PostgreSQL for
     * geometry/geography columns.
     */
    public static function fromWkbHex(string $hex): self
    {
        if (str_starts_with($hex, '\\x')) {
            $hex = substr($hex, 2);
        }

        if ($hex === '' || strlen($hex) % 2 !== 0 || ! ctype_xdigit($hex)) {
            throw new InvalidArgumentException('Value is not a hex-encoded WKB string.');
        }

        $binary = (string) hex2bin($hex);

        if (strlen($binary) < 21) {
            throw new InvalidArgumentException('WKB payload is too short to contain a point.');
        }

        $byteOrder = ord($binary[0]);

        if ($byteOrder !== 0 && $byteOrder !== 1) {
            throw new InvalidArgumentException("Invalid WKB byte order marker [{$byteOrder}].");
        }

        $littleEndian = $byteOrder === 1;
        $uint32 = $littleEndian ? 'V' : 'N';
        $double = $littleEndian ? 'e' : 'E';

        /** @var int $type */
        $type = unpack($uint32, substr($binary, 1, 4))[1];
        $offset = 5;

        $hasZ = ($type & self::EWKB_FLAG_Z) !== 0;
        $hasM = ($type & self::EWKB_FLAG_M) !== 0;
        $hasSrid = ($type & self::EWKB_FLAG_SRID) !== 0;
        $baseType = $type & 0x0FFFFFFF;

        // ISO WKB encodes dimensionality as 1000 (Z), 2000 (M), 3000 (ZM).
        if ($baseType > 1000) {
            $hasZ = $hasZ || in_array(intdiv($baseType, 1000), [1, 3], true);
            $hasM = $hasM || in_array(intdiv($baseType, 1000), [2, 3], true);
            $baseType %= 1000;
        }

        if ($baseType !== self::WKB_POINT) {
            throw new InvalidArgumentException("WKB geometry type [{$baseType}] is not a point.");
        }

        if ($hasSrid) {
            /** @var int $srid */
            $srid = unpack($uint32, substr($binary, $offset, 4))[1];
            $offset += 4;

            if ($srid !== self::SRID) {
                throw new InvalidArgumentException("Unsupported SRID [{$srid}]; expected ".self::SRID.'.');
            }
        }

        $expectedLength = $offset + 16 + ($hasZ ? 8 : 0) + ($hasM ? 8 : 0);

        if (strlen($binary) !== $expectedLength) {
            throw new InvalidArgumentException('WKB payload length does not match its declared point dimensions.');
        }

        /** @var float $x */
        $x = unpack($double, substr($binary, $offset, 8))[1];
        /** @var float $y */
        $y = unpack($double, substr($binary, $offset + 8, 8))[1];

        if (is_nan($x) || is_nan($y)) {
            throw new InvalidArgumentException('WKB point is empty.');
        }

        return new self($y, $x);
    }

    /**
     * Resolves any representation the persistence layer may hand back.
     */
    public static function parse(string $value): self
    {
        $trimmed = ltrim($value);

        if (stripos($trimmed, 'SRID=') === 0 || stripos($trimmed, 'POINT') === 0) {
            return self::fromEwkt($trimmed);
        }

        return self::fromWkbHex($trimmed);
    }

    public function toEwkt(): string
    {
        return sprintf(
            'SRID=%d;POINT(%s %s)',
            self::SRID,
            self::formatCoordinate($this->longitude),
            self::formatCoordinate($this->latitude),
        );
    }

    /**
     * @return array{type: 'Point', coordinates: array{0: float, 1: float}}
     */
    public function toGeoJson(): array
    {
        return ['type' => 'Point', 'coordinates' => [$this->longitude, $this->latitude]];
    }

    /**
     * @return array{lat: float, lng: float}
     */
    public function toArray(): array
    {
        return ['lat' => $this->latitude, 'lng' => $this->longitude];
    }

    /**
     * @return array{lat: float, lng: float}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function equals(self $other, float $epsilon = 1e-9): bool
    {
        return abs($this->latitude - $other->latitude) <= $epsilon
            && abs($this->longitude - $other->longitude) <= $epsilon;
    }

    /**
     * Ten decimal places (~0.01 mm) preserves full GPS precision without
     * emitting float noise such as 34.466000000000001.
     */
    private static function formatCoordinate(float $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');

        return $formatted === '-0' ? '0' : $formatted;
    }
}
