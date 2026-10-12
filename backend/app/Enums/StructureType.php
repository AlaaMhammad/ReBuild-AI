<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum StructureType: string
{
    use HasValues;

    case ResidentialHouse = 'residential_house';
    case ApartmentBuilding = 'apartment_building';
    case Shelter = 'shelter';
    case School = 'school';
    case HealthFacility = 'health_facility';
    case Commercial = 'commercial';
    case Religious = 'religious';
    case Infrastructure = 'infrastructure';
    case Other = 'other';

    public function isResidential(): bool
    {
        return match ($this) {
            self::ResidentialHouse, self::ApartmentBuilding, self::Shelter => true,
            default => false,
        };
    }
}
