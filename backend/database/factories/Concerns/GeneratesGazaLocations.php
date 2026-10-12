<?php

declare(strict_types=1);

namespace Database\Factories\Concerns;

use App\Enums\Governorate;
use App\Support\Geo\GeoPoint;

/**
 * Generates plausible points inside the Gaza Strip for test and demo data.
 *
 * The Strip runs roughly south-west (Rafah) to north-east (Beit Hanoun); points
 * are sampled along that axis with a small perpendicular offset so that they
 * fall on land inside the Strip rather than anywhere in its bounding box.
 */
trait GeneratesGazaLocations
{
    /**
     * @return array{0: GeoPoint, 1: Governorate}
     */
    protected function gazaLocation(): array
    {
        $along = fake()->randomFloat(6, 0, 1);
        $across = fake()->randomFloat(6, -0.018, 0.018);

        $point = GeoPoint::fromLatLng(
            31.262 + $along * 0.285 + $across,
            34.238 + $along * 0.300 - $across,
        );

        $governorate = match (true) {
            $along < 0.18 => Governorate::Rafah,
            $along < 0.40 => Governorate::KhanYunis,
            $along < 0.58 => Governorate::DeirAlBalah,
            $along < 0.85 => Governorate::Gaza,
            default => Governorate::NorthGaza,
        };

        return [$point, $governorate];
    }
}
