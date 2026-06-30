<?php

namespace Abigah\SendIt\Tests;

use Abigah\SendIt\Exceptions\SendItException;
use Abigah\SendIt\Support\ScheduleTime;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ScheduleTimeTest extends TestCase
{
    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        // A fixed "now" so future-validation is deterministic.
        $this->now = CarbonImmutable::create(2026, 6, 29, 12, 0, 0, 'UTC');
    }

    public function test_it_interprets_the_value_in_the_app_timezone_and_returns_utc(): void
    {
        // The fieldtype hands us a value already in the app timezone. With a
        // non-UTC app timezone, the resulting instant must be shifted to UTC.
        $time = ScheduleTime::forMailchimp('2026-07-01 09:00', 'America/Vancouver', $this->now);

        // 09:00 in Vancouver (PDT, -07:00) is 16:00 UTC.
        $this->assertSame('2026-07-01T16:00:00+00:00', $time->toIso8601String());
    }

    public function test_a_utc_app_timezone_value_passes_through_unshifted(): void
    {
        $time = ScheduleTime::forMailchimp('2026-07-01 16:00', 'UTC', $this->now);

        $this->assertSame('2026-07-01T16:00:00+00:00', $time->toIso8601String());
    }

    public function test_it_rounds_up_to_the_next_quarter_hour(): void
    {
        $time = ScheduleTime::forMailchimp('2026-07-01 16:07', 'UTC', $this->now);

        $this->assertSame('2026-07-01T16:15:00+00:00', $time->toIso8601String());
    }

    public function test_it_leaves_an_exact_quarter_hour_untouched(): void
    {
        $time = ScheduleTime::forMailchimp('2026-07-01 16:30', 'UTC', $this->now);

        $this->assertSame('2026-07-01T16:30:00+00:00', $time->toIso8601String());
    }

    public function test_it_rejects_an_empty_value(): void
    {
        $this->expectException(SendItException::class);

        ScheduleTime::forMailchimp('', 'UTC', $this->now);
    }

    public function test_it_rejects_a_time_in_the_past(): void
    {
        $this->expectException(SendItException::class);

        ScheduleTime::forMailchimp('2026-06-29 11:00', 'UTC', $this->now);
    }
}
