<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum PriorityLevel: string
{
    use HasValues;

    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
}
