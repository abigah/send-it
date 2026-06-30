<?php

namespace Abigah\SendIt\Support;

use Carbon\CarbonImmutable;
use Statamic\Contracts\Entries\Entry;

/**
 * Ensures an entry is actually live at send time: published, and — for dated
 * collections that hide future-dated entries — not still in the future.
 */
class EntryPublisher
{
    public function ensurePublished(Entry $entry): bool
    {
        $changed = false;

        if (! $entry->published()) {
            $entry->published(true);
            $changed = true;
        }

        $collection = $entry->collection();

        if ($collection?->dated() && $collection->futureDateBehavior() === 'private') {
            $date = $entry->date();

            if ($date && $date->isFuture()) {
                $entry->date(CarbonImmutable::now());
                $changed = true;
            }
        }

        if ($changed) {
            $entry->save();
        }

        return $changed;
    }
}
