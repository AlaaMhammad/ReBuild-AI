<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Enums\DamageTier;
use App\Enums\NeedCategory;
use App\Models\DamageAssessment;
use App\Models\Organization;
use App\Support\Geo\GeoPoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DamageAssessmentSpatialTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Metres per degree of latitude near 31.5°N.
     */
    private const METRES_PER_DEGREE_LAT = 110_900.0;

    private GeoPoint $center;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->center = GeoPoint::fromLatLng(31.5017, 34.4668); // Gaza City
        $this->organization = Organization::factory()->create();
    }

    #[Test]
    public function locations_round_trip_through_postgis_unchanged(): void
    {
        $assessment = $this->assessmentAt($this->center);

        $reloaded = DamageAssessment::query()->findOrFail($assessment->id);

        self::assertInstanceOf(GeoPoint::class, $reloaded->location);
        self::assertTrue($this->center->equals($reloaded->location));
        self::assertFalse($reloaded->isDirty('location'));

        $reloaded->location = GeoPoint::fromLatLng($this->center->latitude, $this->center->longitude);
        self::assertFalse($reloaded->isDirty('location'), 'Re-assigning the same point is not a change.');

        $reloaded->location = $this->offsetNorth($this->center, 5);
        self::assertTrue($reloaded->isDirty('location'), 'Moving the point is a change.');

        $stored = DB::selectOne('SELECT ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng FROM damage_assessments WHERE id = ?', [$assessment->id]);
        self::assertEqualsWithDelta(31.5017, (float) $stored->lat, 1e-9);
        self::assertEqualsWithDelta(34.4668, (float) $stored->lng, 1e-9);
    }

    #[Test]
    public function within_radius_uses_metres_on_the_spheroid(): void
    {
        $atCenter = $this->assessmentAt($this->center);
        $tenMetresNorth = $this->assessmentAt($this->offsetNorth($this->center, 10));
        $this->assessmentAt($this->offsetNorth($this->center, 200));

        $ids = DamageAssessment::query()->withinRadius($this->center, 15)->pluck('id')->all();

        self::assertEqualsCanonicalizing([$atCenter->id, $tenMetresNorth->id], $ids);
    }

    #[Test]
    public function results_can_be_ordered_by_and_annotated_with_distance(): void
    {
        $far = $this->assessmentAt($this->offsetNorth($this->center, 900));
        $near = $this->assessmentAt($this->offsetNorth($this->center, 50));
        $middle = $this->assessmentAt($this->offsetNorth($this->center, 400));

        $results = DamageAssessment::query()
            ->withDistanceFrom($this->center)
            ->orderByDistance($this->center)
            ->get();

        self::assertSame([$near->id, $middle->id, $far->id], $results->pluck('id')->all());
        self::assertEqualsWithDelta(50.0, (float) $results->first()->getAttribute('distance_m'), 1.0);
        self::assertInstanceOf(GeoPoint::class, $results->first()->location);
    }

    #[Test]
    public function bounding_box_filters_a_map_viewport(): void
    {
        $inside = $this->assessmentAt(GeoPoint::fromLatLng(31.52, 34.45));
        $this->assessmentAt(GeoPoint::fromLatLng(31.30, 34.25));

        $ids = DamageAssessment::query()->withinBoundingBox(34.40, 31.48, 34.50, 31.56)->pluck('id')->all();

        self::assertSame([$inside->id], $ids);
    }

    #[Test]
    public function geojson_area_filters_by_polygon(): void
    {
        $inside = $this->assessmentAt(GeoPoint::fromLatLng(31.35, 34.30));
        $this->assessmentAt(GeoPoint::fromLatLng(31.52, 34.45));

        $khanYunisArea = [
            'type' => 'Polygon',
            'coordinates' => [[[34.25, 31.30], [34.36, 31.30], [34.36, 31.40], [34.25, 31.40], [34.25, 31.30]]],
        ];

        self::assertSame([$inside->id], DamageAssessment::query()->withinGeoJsonArea($khanYunisArea)->pluck('id')->all());
        self::assertSame([$inside->id], DamageAssessment::query()->withinGeoJsonArea((string) json_encode($khanYunisArea))->pluck('id')->all());
    }

    #[Test]
    public function the_radius_query_is_served_by_the_gist_index(): void
    {
        DB::statement('SET enable_seqscan = off');

        $plan = collect(DB::select(
            'EXPLAIN '.DamageAssessment::query()->withinRadius($this->center, 15)->toRawSql()
        ))->pluck('QUERY PLAN')->implode("\n");

        self::assertStringContainsString('damage_assessments_location_spatialindex', $plan);
    }

    #[Test]
    public function non_spatial_scopes_filter_by_tier_and_need(): void
    {
        $destroyed = DamageAssessment::factory()->for($this->organization)->tier(DamageTier::Destroyed)->create([
            'urgent_needs' => [['category' => NeedCategory::Water->value, 'quantity' => 3]],
        ]);
        DamageAssessment::factory()->for($this->organization)->tier(DamageTier::Minor)->create(['urgent_needs' => []]);

        self::assertSame([$destroyed->id], DamageAssessment::query()->withMinimumDamage(DamageTier::Severe)->pluck('id')->all());
        self::assertSame([$destroyed->id], DamageAssessment::query()->needingCategory(NeedCategory::Water)->pluck('id')->all());
    }

    #[Test]
    public function invalid_radius_is_rejected_before_querying(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DamageAssessment::query()->withinRadius($this->center, 0);
    }

    private function assessmentAt(GeoPoint $location): DamageAssessment
    {
        return DamageAssessment::factory()->for($this->organization)->at($location)->create();
    }

    private function offsetNorth(GeoPoint $from, float $metres): GeoPoint
    {
        return GeoPoint::fromLatLng($from->latitude + $metres / self::METRES_PER_DEGREE_LAT, $from->longitude);
    }
}
