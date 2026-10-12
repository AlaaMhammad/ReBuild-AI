<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * The five administrative governorates of the Gaza Strip.
 */
enum Governorate: string
{
    use HasValues;

    case NorthGaza = 'north_gaza';
    case Gaza = 'gaza';
    case DeirAlBalah = 'deir_al_balah';
    case KhanYunis = 'khan_yunis';
    case Rafah = 'rafah';
}
