<?php

namespace Abigah\SendIt\Support;

use Abigah\SendIt\Exceptions\SendItException;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Resolves a control-panel schedule value into a UTC instant for the scheduler.
 *
 * The value arrives already processed by Statamic's date fieldtype: the user
 * picks a time in their own timezone, the fieldtype converts it and hands us a
 * string in the application timezone (config('app.timezone')). We parse it back
 * in that same timezone so the absolute instant is preserved, then normalise to
 * UTC at minute precision — the granularity the every-minute scheduler runs at.
 */
class ScheduleTime
{
    public static function resolve(string $value, string $appTimezone, ?DateTimeInterface $now = null): CarbonImmutable
    {
        $value = trim($value);

        if ($value === '') {
            throw new SendItException('A schedule time is required to schedule a send.');
        }

        $time = CarbonImmutable::parse($value, $appTimezone ?: 'UTC')->utc()->seconds(0);

        $reference = $now
            ? CarbonImmutable::instance($now)->utc()
            : CarbonImmutable::now('UTC');

        if ($time->lessThanOrEqualTo($reference)) {
            throw new SendItException('The schedule time must be in the future.');
        }

        return $time;
    }
}
