<?php

declare(strict_types=1);

namespace Tests\Unit\Sync;

use App\Services\Sync\DeviceClock;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DeviceClockTest extends TestCase
{
    private CarbonImmutable $receivedAt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->receivedAt = CarbonImmutable::parse('2026-10-12T08:00:00Z');
    }

    #[Test]
    public function small_skew_leaves_timestamps_untouched_but_normalizes_to_utc(): void
    {
        $clock = new DeviceClock($this->receivedAt, 40, 300);

        $result = $clock->toServerTime('2026-10-12T10:00:00+03:00');

        self::assertFalse($clock->isCorrecting());
        self::assertSame('2026-10-12T07:00:00+00:00', $result['value']?->toIso8601String());
        self::assertFalse($result['clamped']);
    }

    #[Test]
    public function large_negative_skew_shifts_timestamps_back(): void
    {
        // Device clock one day ahead.
        $clock = new DeviceClock($this->receivedAt, -86_400, 300);

        $result = $clock->toServerTime('2026-10-13T07:30:00Z');

        self::assertTrue($clock->isCorrecting());
        self::assertSame('2026-10-12T07:30:00+00:00', $result['value']?->toIso8601String());
    }

    #[Test]
    public function impossible_future_values_are_clamped(): void
    {
        $clock = new DeviceClock($this->receivedAt, 0, 300);

        $withinTolerance = $clock->toServerTime('2026-10-12T08:04:00Z');
        $beyond = $clock->toServerTime('2026-10-12T09:00:00Z');

        self::assertFalse($withinTolerance['clamped']);
        self::assertTrue($beyond['clamped']);
        self::assertTrue($beyond['value']?->equalTo($this->receivedAt));
    }

    #[Test]
    public function null_and_empty_values_pass_through(): void
    {
        $clock = new DeviceClock($this->receivedAt, 0, 300);

        self::assertSame(['value' => null, 'clamped' => false], $clock->toServerTime(null));
        self::assertSame(['value' => null, 'clamped' => false], $clock->toServerTime(''));
    }

    #[Test]
    public function skew_is_measured_as_received_minus_sent(): void
    {
        $clock = DeviceClock::forBatch(CarbonImmutable::parse('2026-10-12T06:00:00Z'), $this->receivedAt, 300);

        self::assertSame(7200, $clock->skewSeconds);
    }
}
