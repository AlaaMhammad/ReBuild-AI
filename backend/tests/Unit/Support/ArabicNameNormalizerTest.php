<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Text\ArabicNameNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ArabicNameNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function equivalentSpellings(): iterable
    {
        yield 'alef with hamza' => ['أحمد', 'احمد'];
        yield 'alef with hamza below' => ['إبراهيم', 'ابراهيم'];
        yield 'alef madda' => ['آمنة', 'امنه'];
        yield 'taa marbuta' => ['فاطمة', 'فاطمه'];
        yield 'alef maqsura' => ['مصطفى', 'مصطفي'];
        yield 'diacritics' => ['مُحَمَّد', 'محمد'];
        yield 'tatweel' => ['محـــمد', 'محمد'];
        yield 'whitespace and punctuation' => ['  محمد   -  علي ', 'محمد علي'];
        yield 'latin case folding' => ['Mohammed ABU Ali', 'mohammed abu ali'];
    }

    #[Test]
    #[DataProvider('equivalentSpellings')]
    public function it_normalizes_common_spelling_variants(string $input, string $expected): void
    {
        self::assertSame($expected, ArabicNameNormalizer::normalize($input));
    }

    #[Test]
    public function it_is_idempotent(): void
    {
        $once = ArabicNameNormalizer::normalize('عبدُ الرَّحمن أبو شَعبان');

        self::assertSame($once, ArabicNameNormalizer::normalize($once));
    }
}
