<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum DisplacementStatus: string
{
    use HasValues;

    case Resident = 'resident';
    case DisplacedShelter = 'displaced_shelter';
    case DisplacedHostFamily = 'displaced_host_family';
    case DisplacedTent = 'displaced_tent';
    case Returnee = 'returnee';

    public function isDisplaced(): bool
    {
        return match ($this) {
            self::DisplacedShelter, self::DisplacedHostFamily, self::DisplacedTent => true,
            self::Resident, self::Returnee => false,
        };
    }
}
