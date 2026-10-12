<?php

declare(strict_types=1);

namespace App\Enums\Concerns;

/**
 * Helpers for string-backed enums.
 */
trait HasValues
{
    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
