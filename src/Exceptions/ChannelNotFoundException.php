<?php

namespace Abigah\SendIt\Exceptions;

class ChannelNotFoundException extends SendItException
{
    public static function for(string $key): self
    {
        return new self("No Send It channel registered for [{$key}].");
    }
}
