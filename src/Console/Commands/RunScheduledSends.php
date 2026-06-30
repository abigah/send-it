<?php

namespace Abigah\SendIt\Console\Commands;

use Abigah\SendIt\Channels\ChannelManager;
use Abigah\SendIt\Scheduling\ScheduledSend;
use Abigah\SendIt\Scheduling\ScheduleStore;
use Abigah\SendIt\Support\EntryPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RuntimeException;
use Statamic\Facades\Entry;
use Throwable;

class RunScheduledSends extends Command
{
    protected $signature = 'send-it:run-scheduled';

    protected $description = 'Publish and send any scheduled newsletters whose time has arrived.';

    public function handle(ScheduleStore $store, ChannelManager $channels, EntryPublisher $publisher): int
    {
        $due = $store->due(CarbonImmutable::now('UTC'));

        if ($due === []) {
            return self::SUCCESS;
        }

        foreach ($due as $send) {
            $this->process($send, $store, $channels, $publisher);
        }

        return self::SUCCESS;
    }

    protected function process(
        ScheduledSend $send,
        ScheduleStore $store,
        ChannelManager $channels,
        EntryPublisher $publisher,
    ): void {
        try {
            $entry = Entry::find($send->entry);

            if (! $entry) {
                throw new RuntimeException("Entry [{$send->entry}] no longer exists.");
            }

            // Make sure the article is live before the newsletter links to it.
            $publisher->ensurePublished($entry);

            $result = $channels->channel($send->channel)->send($entry, $send->options);

            $store->update($send->markedAs(
                $result->success ? 'sent' : 'failed',
                $result->message,
                CarbonImmutable::now('UTC'),
            ));

            $result->success
                ? $this->info("Sent [{$send->entry}] via {$send->channel}: {$result->message}")
                : $this->error("Send failed for [{$send->entry}]: {$result->message}");
        } catch (Throwable $e) {
            $store->update($send->markedAs('failed', $e->getMessage(), CarbonImmutable::now('UTC')));

            report($e);
            $this->error("Scheduled send for [{$send->entry}] errored: {$e->getMessage()}");
        }
    }
}
