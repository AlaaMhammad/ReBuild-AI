<?php

declare(strict_types=1);

namespace App\Support\Text;

/**
 * Normalizes personal names for fuzzy matching (pg_trgm) across spelling
 * variants that field agents routinely produce for the same person.
 *
 * Applied rules: strip diacritics (tashkeel) and tatweel, unify alef forms,
 * alef maqsura -> yaa, taa marbuta -> haa, hamza carriers -> base letter,
 * Latin case folding, and whitespace collapsing.
 */
final class ArabicNameNormalizer
{
    private const REPLACEMENTS = [
        'أ' => 'ا',
        'إ' => 'ا',
        'آ' => 'ا',
        'ٱ' => 'ا',
        'ى' => 'ي',
        'ة' => 'ه',
        'ؤ' => 'و',
        'ئ' => 'ي',
        'ء' => '',
    ];

    public static function normalize(string $name): string
    {
        // Harakat, superscript alef and tatweel.
        $stripped = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $name);

        $unified = strtr($stripped, self::REPLACEMENTS);

        $folded = mb_strtolower($unified, 'UTF-8');

        // Anything that is not a letter, a combining mark or a space becomes a separator.
        $cleaned = (string) preg_replace('/[^\p{L}\p{M}\s]+/u', ' ', $folded);

        return trim((string) preg_replace('/\s+/u', ' ', $cleaned));
    }
}
