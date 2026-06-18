<?php

namespace Abigah\SendIt\Support;

use Statamic\Fields\Value;
use Statamic\Contracts\Entries\Entry;

class EntryContent
{
    /**
     * Resolve the augmented HTML body for an entry from the given field.
     *
     * Handles the common body fieldtypes:
     *  - Markdown / Textarea / HTML augment to an HTML (or plain) string.
     *  - Bard augments to an array of nodes where text nodes already carry
     *    rendered HTML; those are concatenated. (Bard "sets" are not rendered
     *    here — they would need their own email-safe markup.)
     */
    public static function html(Entry $entry, string $field = 'content'): string
    {
        return trim(static::toHtml($entry->augmentedValue($field)));
    }

    /**
     * A reasonable subject/title for the entry.
     */
    public static function title(Entry $entry): string
    {
        return (string) ($entry->value('title') ?? $entry->get('title') ?? $entry->id());
    }

    protected static function toHtml(mixed $value): string
    {
        if ($value instanceof Value) {
            return static::toHtml($value->value());
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            // Bard: array of nodes (each a plain array or an array-accessible
            // Statamic\Fields\Values). Text nodes hold pre-rendered HTML.
            return collect($value)
                ->map(function ($node) {
                    $accessible = is_array($node) || $node instanceof \ArrayAccess;

                    return $accessible && ($node['type'] ?? null) === 'text'
                        ? (string) ($node['text'] ?? '')
                        : '';
                })
                ->implode('');
        }

        return (string) $value;
    }
}
