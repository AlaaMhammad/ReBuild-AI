<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum Gender: string
{
    use HasValues;

    case Male = 'male';
    case Female = 'female';
}
