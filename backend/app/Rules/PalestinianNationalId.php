<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Identity\NationalId;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accepts a 9-digit Palestinian ID (ASCII or Arabic-Indic digits, optional
 * separators) whose check digit is valid.
 */
final class PalestinianNationalId implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! is_int($value)) {
            $fail('The :attribute must be a national ID number.');

            return;
        }

        if (! NationalId::isValid((string) $value)) {
            $fail('The :attribute is not a valid Palestinian national ID (check digit mismatch or wrong length).');
        }
    }
}
