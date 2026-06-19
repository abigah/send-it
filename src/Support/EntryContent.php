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

    /**
     * Resolve the entry's author name(s) from a users (or string) field.
     *
     * @return array<int, string>
     */
    public static function authors(Entry $entry, string $field = 'author'): array
    {
        if (! $entry->blueprint()?->hasField($field)) {
            return [];
        }

        $value = $entry->augmentedValue($field)?->value();

        // A users fieldtype augments to a query builder; resolve it.
        if (is_object($value) && method_exists($value, 'get') && ! $value instanceof \Illuminate\Support\Collection) {
            $value = $value->get();
        }

        if ($value === null) {
            return [];
        }

        return collect(is_iterable($value) ? $value : [$value])
            ->map(fn ($author) => static::authorName($author))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The entry's date, formatted, or null when the collection isn't dated.
     */
    public static function date(Entry $entry, string $format = 'F j, Y'): ?string
    {
        return $entry->hasDate() ? $entry->date()?->format($format) : null;
    }

    /**
     * Title, author names, and formatted date for the article header. Uses the
     * email.author_field / email.date_format config.
     *
     * @return array{title: string, authors: string, date: ?string}
     */
    public static function articleMeta(Entry $entry): array
    {
        return [
            'title' => static::title($entry),
            'authors' => implode(', ', static::authors($entry, config('send-it.email.author_field', 'author'))),
            'date' => static::date($entry, config('send-it.email.date_format', 'F j, Y')),
        ];
    }

    protected static function authorName(mixed $author): string
    {
        if (is_object($author) && method_exists($author, 'name')) {
            return (string) $author->name();
        }

        if (is_object($author) && method_exists($author, 'title')) {
            return (string) $author->title();
        }

        return is_string($author) ? $author : '';
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
