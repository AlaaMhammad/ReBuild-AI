<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Geo\GeoPoint;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GeoPointTest extends TestCase
{
    #[Test]
    public function it_serializes_to_ewkt_with_longitude_first(): void
    {
        $point = GeoPoint::fromLatLng(31.5017, 34.4668);

        self::assertSame('SRID=4326;POINT(34.4668 31.5017)', $point->toEwkt());
        self::assertSame(['type' => 'Point', 'coordinates' => [34.4668, 31.5017]], $point->toGeoJson());
        self::assertSame('{"lat":31.5017,"lng":34.4668}', json_encode($point));
    }

    #[Test]
    public function it_parses_little_endian_ewkb_with_srid_as_returned_by_postgis(): void
    {
        // SELECT ST_SetSRID(ST_MakePoint(34.4668, 31.5017), 4326)::geography;
        $hex = '0101000020E6100000'.bin2hex(pack('e', 34.4668)).bin2hex(pack('e', 31.5017));

        $point = GeoPoint::fromWkbHex($hex);

        self::assertEqualsWithDelta(31.5017, $point->latitude, 1e-12);
        self::assertEqualsWithDelta(34.4668, $point->longitude, 1e-12);
    }

    #[Test]
    public function it_parses_big_endian_iso_wkb_without_srid(): void
    {
        $hex = '00'.'00000001'.bin2hex(pack('E', -0.1278)).bin2hex(pack('E', 51.5074));

        $point = GeoPoint::fromWkbHex($hex);

        self::assertEqualsWithDelta(51.5074, $point->latitude, 1e-12);
        self::assertEqualsWithDelta(-0.1278, $point->longitude, 1e-12);
    }

    #[Test]
    public function it_parses_ewkb_with_a_z_dimension(): void
    {
        $hex = '01'.bin2hex(pack('V', 0x80000001 | 0x20000000)).bin2hex(pack('V', 4326))
            .bin2hex(pack('e', 34.3)).bin2hex(pack('e', 31.3)).bin2hex(pack('e', 12.0));

        $point = GeoPoint::fromWkbHex($hex);

        self::assertEqualsWithDelta(31.3, $point->latitude, 1e-12);
    }

    #[Test]
    public function it_round_trips_through_ewkt(): void
    {
        $original = GeoPoint::fromLatLng(31.2768123456, 34.2512987654);

        self::assertTrue($original->equals(GeoPoint::parse($original->toEwkt())));
    }

    #[Test]
    public function it_accepts_both_array_key_styles(): void
    {
        self::assertTrue(GeoPoint::fromArray(['lat' => 31.5, 'lng' => 34.4])->equals(GeoPoint::fromLatLng(31.5, 34.4)));
        self::assertTrue(GeoPoint::fromArray(['latitude' => '31.5', 'longitude' => '34.4'])->equals(GeoPoint::fromLatLng(31.5, 34.4)));
    }

    /**
     * @return iterable<string, array{0: float, 1: float}>
     */
    public static function invalidCoordinates(): iterable
    {
        yield 'latitude above 90' => [90.0001, 34.0];
        yield 'latitude below -90' => [-91.0, 34.0];
        yield 'longitude above 180' => [31.0, 180.5];
        yield 'NaN latitude' => [NAN, 34.0];
        yield 'infinite longitude' => [31.0, INF];
    }

    #[Test]
    #[DataProvider('invalidCoordinates')]
    public function it_rejects_out_of_range_coordinates(float $latitude, float $longitude): void
    {
        $this->expectException(InvalidArgumentException::class);

        GeoPoint::fromLatLng($latitude, $longitude);
    }

    #[Test]
    public function it_rejects_a_foreign_srid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported SRID [3857]');

        GeoPoint::fromEwkt('SRID=3857;POINT(3836000 3696000)');
    }

    #[Test]
    public function it_rejects_non_point_wkb(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // LINESTRING type (2) with SRID flag.
        GeoPoint::fromWkbHex('0102000020E6100000'.str_repeat('00', 20));
    }

    #[Test]
    public function it_rejects_an_empty_point(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('empty');

        GeoPoint::fromWkbHex('0101000020E6100000'.bin2hex(pack('e', NAN)).bin2hex(pack('e', NAN)));
    }

    #[Test]
    public function it_rejects_truncated_payloads(): void
    {
        $this->expectException(InvalidArgumentException::class);

        GeoPoint::fromWkbHex('0101000020E6100000'.bin2hex(pack('e', 34.4)));
    }
}
