<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum HabitabilityStatus: string
{
    use HasValues;

    case Habitable = 'habitable';
    case PartiallyHabitable = 'partially_habitable';
    case Uninhabitable = 'uninhabitable';
}
