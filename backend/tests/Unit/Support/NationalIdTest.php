<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Identity\NationalId;
use App\Support\Identity\NationalIdHasher;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NationalIdTest extends TestCase
{
    private const KEY = 'unit-test-national-id-hmac-key-0123456789';

    #[Test]
    public function it_generates_ids_that_pass_validation(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $id = NationalId::withCheckDigit(str_pad((string) random_int(1, 99_999_999), 8, '0', STR_PAD_LEFT));

            self::assertTrue(NationalId::isValid($id), "Generated ID {$id} failed its own checksum.");
        }
    }

    #[Test]
    public function it_detects_a_single_digit_typo(): void
    {
        $valid = NationalId::withCheckDigit('40123456');
        $typo = substr($valid, 0, 3).(string) (((int) $valid[3] + 1) % 10).substr($valid, 4);

        self::assertTrue(NationalId::isValid($valid));
        self::assertFalse(NationalId::isValid($typo));
    }

    #[Test]
    public function it_normalizes_arabic_indic_digits_and_separators(): void
    {
        self::assertSame('401234567', NationalId::normalize('٤٠١-٢٣٤ ٥٦٧'));
        self::assertSame('401234567', NationalId::normalize('۴۰۱۲۳۴۵۶۷'));
        self::assertSame('012345678', NationalId::normalize('12345678'));
    }

    #[Test]
    public function it_rejects_non_numeric_or_overlong_values(): void
    {
        self::assertFalse(NationalId::isValid('40123456A'));
        self::assertFalse(NationalId::isValid('4012345678'));
        self::assertFalse(NationalId::isValid('000000000'));
        self::assertFalse(NationalId::isValid(''));

        $this->expectException(InvalidArgumentException::class);
        NationalId::normalize('abc');
    }

    #[Test]
    public function it_masks_all_but_the_last_four_digits(): void
    {
        self::assertSame('*****4567', NationalId::mask('401234567'));
    }

    #[Test]
    public function the_hash_is_deterministic_keyed_and_format_insensitive(): void
    {
        $hasher = new NationalIdHasher(self::KEY);
        $other = new NationalIdHasher(self::KEY.'-different');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hasher->hash('401234567'));
        self::assertSame($hasher->hash('401234567'), $hasher->hash('٤٠١٢٣٤٥٦٧'));
        self::assertNotSame($hasher->hash('401234567'), $other->hash('401234567'));
        self::assertNotSame(hash('sha256', '401234567'), $hasher->hash('401234567'));
    }

    #[Test]
    public function the_hasher_decodes_base64_keys(): void
    {
        $raw = random_bytes(32);

        self::assertSame(
            (new NationalIdHasher($raw))->hash('401234567'),
            NationalIdHasher::fromConfig('base64:'.base64_encode($raw))->hash('401234567'),
        );
    }

    #[Test]
    public function the_hasher_refuses_weak_or_missing_keys(): void
    {
        $this->expectException(RuntimeException::class);

        NationalIdHasher::fromConfig(null);
    }
}
