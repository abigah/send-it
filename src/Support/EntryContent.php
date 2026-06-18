<?php

namespace Abigah\SendIt\Support;

use Statamic\Contracts\Entries\Entry;

class EntryContent
{
    /**
     * Resolve the augmented HTML body for an entry from the given field.
     * Markdown and Bard fields augment to their rendered HTML when cast
     * to a string.
     */
    public static function html(Entry $entry, string $field = 'content'): string
    {
        $value = $entry->augmentedValue($field);

        return trim((string) $value);
    }

    /**
     * A reasonable subject/title for the entry.
     */
    public static function title(Entry $entry): string
    {
        return (string) ($entry->value('title') ?? $entry->get('title') ?? $entry->id());
    }
}
