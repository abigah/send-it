<?php

namespace Abigah\SendIt\Support;

/**
 * Builds a personalised greeting from a format string containing a
 * {first_name} placeholder, producing channel-appropriate output.
 */
class Greeting
{
    /**
     * Greeting for a known (or unknown) recipient name — used by channels that
     * send to a concrete address. Falls back when no name is available.
     */
    public static function forName(?string $format, ?string $name, string $fallback): ?string
    {
        if (empty($format)) {
            return null;
        }

        return str_replace('{first_name}', static::firstName($name) ?: $fallback, $format);
    }

    /**
     * Greeting for Mailchimp, using the *|FNAME|* merge tag with a conditional
     * fallback so subscribers without a first name still get a clean greeting.
     */
    public static function forMailchimp(?string $format, string $fallback): ?string
    {
        if (empty($format)) {
            return null;
        }

        return '*|IF:FNAME|*'
            .str_replace('{first_name}', '*|FNAME|*', $format)
            .'*|ELSE:|*'
            .str_replace('{first_name}', $fallback, $format)
            .'*|END:IF|*';
    }

    protected static function firstName(?string $name): string
    {
        $name = trim((string) $name);

        return $name === '' ? '' : explode(' ', $name)[0];
    }
}
