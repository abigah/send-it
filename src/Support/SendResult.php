<?php

namespace Abigah\SendIt\Support;

use Statamic\Contracts\Entries\Entry;

class SendResult
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $channel,
        public readonly string $message,
        public readonly ?Entry $entry = null,
        public readonly array $meta = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function success(string $channel, string $message, ?Entry $entry = null, array $meta = []): self
    {
        return new self(true, $channel, $message, $entry, $meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function failure(string $channel, string $message, ?Entry $entry = null, array $meta = []): self
    {
        return new self(false, $channel, $message, $entry, $meta);
    }
}
