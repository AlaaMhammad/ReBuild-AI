<?php

declare(strict_types=1);

namespace App\Support\Identity;

use InvalidArgumentException;

/**
 * Palestinian Population Registry ID numbers: 9 digits, the last being a
 * Luhn-style check digit (digits at odd positions are doubled, 1-indexed
 * from the left, and digit sums above 9 are reduced by 9).
 */
final class NationalId
{
    public const LENGTH = 9;

    private const ARABIC_INDIC_DIGITS = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    private const EXTENDED_ARABIC_INDIC_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    /**
     * Converts Arabic-Indic digits to ASCII, strips separators and left-pads to 9 digits.
     *
     * @throws InvalidArgumentException when the value cannot be a national ID
     */
    public static function normalize(string $raw): string
    {
        $asciiDigits = array_map('strval', range(0, 9));

        $ascii = str_replace(
            [...self::ARABIC_INDIC_DIGITS, ...self::EXTENDED_ARABIC_INDIC_DIGITS],
            [...$asciiDigits, ...$asciiDigits],
            $raw,
        );

        $digits = (string) preg_replace('/[\s\-.\/]/u', '', $ascii);

        if ($digits === '' || ! ctype_digit($digits) || strlen($digits) > self::LENGTH) {
            throw new InvalidArgumentException('A national ID must contain between 1 and 9 digits.');
        }

        return str_pad($digits, self::LENGTH, '0', STR_PAD_LEFT);
    }

    public static function isValid(string $raw): bool
    {
        try {
            $normalized = self::normalize($raw);
        } catch (InvalidArgumentException) {
            return false;
        }

        if ($normalized === str_repeat('0', self::LENGTH)) {
            return false;
        }

        return self::checksum($normalized) % 10 === 0;
    }

    /**
     * Appends the check digit to an 8-digit body.
     */
    public static function withCheckDigit(string $body): string
    {
        if (strlen($body) !== self::LENGTH - 1 || ! ctype_digit($body)) {
            throw new InvalidArgumentException('The ID body must be exactly 8 digits.');
        }

        $remainder = self::checksum($body.'0') % 10;

        return $body.(string) ((10 - $remainder) % 10);
    }

    /**
     * Masks all but the last four digits, e.g. "*****6789".
     */
    public static function mask(string $normalized): string
    {
        return str_repeat('*', max(0, strlen($normalized) - 4)).substr($normalized, -4);
    }

    private static function checksum(string $nineDigits): int
    {
        $sum = 0;

        for ($i = 0; $i < self::LENGTH; $i++) {
            $digit = (int) $nineDigits[$i] * (($i % 2) + 1);
            $sum += $digit > 9 ? $digit - 9 : $digit;
        }

        return $sum;
    }
}
