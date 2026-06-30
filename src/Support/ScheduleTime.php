<?php

namespace Abigah\SendIt\Support;

use Abigah\SendIt\Exceptions\SendItException;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Resolves a control-panel schedule value into a UTC instant Mailchimp accepts.
 *
 * The value arrives already processed by Statamic's date fieldtype: the user
 * picks a time in their own timezone, the fieldtype converts it and hands us a
 * string in the application timezone (config('app.timezone')). We parse it back
 * in that same timezone so the absolute instant is preserved, convert to UTC,
 * and round up to the next quarter hour — the only times Mailchimp will accept.
 */
class ScheduleTime
{
    /** Mailchimp only schedules on quarter-hour boundaries. */
    public const INTERVAL_MINUTES = 15;

    public static function forMailchimp(string $value, string $appTimezone, ?DateTimeInterface $now = null): CarbonImmutable
    {
        $value = trim($value);

        if ($value === '') {
            throw new SendItException('A schedule time is required to schedule a campaign.');
        }

        $time = self::ceilToInterval(
            CarbonImmutable::parse($value, $appTimezone ?: 'UTC')->utc()
        );

        $reference = $now
            ? CarbonImmutable::instance($now)->utc()
            : CarbonImmutable::now('UTC');

        if ($time->lessThanOrEqualTo($reference)) {
            throw new SendItException('The schedule time must be in the future.');
        }

        return $time;
    }

    private static function ceilToInterval(CarbonImmutable $time): CarbonImmutable
    {
        $time = $time->seconds(0);

        $remainder = $time->minute % self::INTERVAL_MINUTES;

        return $remainder === 0
            ? $time
            : $time->addMinutes(self::INTERVAL_MINUTES - $remainder);
    }
}
