<?php

declare(strict_types=1);

namespace App\Services\Sync;

use Carbon\CarbonImmutable;

/**
 * Translates device timestamps into server time for one batch.
 *
 * skew = received_at − sent_at (positive when the device clock is behind).
 *
 *  1. Every timestamp is parsed with its offset and converted to UTC
 *     (timestamptz columns are written in UTC).
 *  2. When |skew| exceeds the tolerance, the device clock is considered wrong
 *     and every timestamp is shifted by the skew. This assumes the offset was
 *     constant between capture and send, which holds for the common cases
 *     (unset clock after battery loss, wrong time zone).
 *  3. A timestamp that is still later than received_at + tolerance is
 *     physically impossible and is clamped to received_at.
 *
 * Network transit time is included in the measured skew; it is negligible
 * compared with the tolerance (default 300 s).
 */
final class DeviceClock
{
    private readonly bool $corrects;

    public function __construct(
        public readonly CarbonImmutable $receivedAt,
        public readonly int $skewSeconds,
        private readonly int $toleranceSeconds,
    ) {
        $this->corrects = abs($skewSeconds) > $toleranceSeconds;
    }

    public static function forBatch(CarbonImmutable $sentAt, CarbonImmutable $receivedAt, ?int $toleranceSeconds = null): self
    {
        return new self(
            $receivedAt->utc(),
            (int) $sentAt->diffInSeconds($receivedAt, false),
            $toleranceSeconds ?? (int) config('rebuild.sync.future_timestamp_tolerance_seconds'),
        );
    }

    public function isCorrecting(): bool
    {
        return $this->corrects;
    }

    /**
     * @return array{value: CarbonImmutable|null, clamped: bool}
     */
    public function toServerTime(?string $deviceTimestamp): array
    {
        if ($deviceTimestamp === null || $deviceTimestamp === '') {
            return ['value' => null, 'clamped' => false];
        }

        $value = CarbonImmutable::parse($deviceTimestamp)->utc();

        if ($this->corrects) {
            $value = $value->addSeconds($this->skewSeconds);
        }

        if ($value->greaterThan($this->receivedAt->addSeconds($this->toleranceSeconds))) {
            return ['value' => $this->receivedAt, 'clamped' => true];
        }

        return ['value' => $value, 'clamped' => false];
    }
}
