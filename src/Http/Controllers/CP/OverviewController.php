<?php

namespace Abigah\SendIt\Http\Controllers\CP;

use Abigah\SendIt\Channels\ChannelManager;
use Abigah\SendIt\Contracts\Channel;
use Abigah\SendIt\Push\DeviceStore;
use Abigah\SendIt\Scheduling\ScheduledSend;
use Abigah\SendIt\Scheduling\ScheduleStore;
use Statamic\Facades\Entry;
use Statamic\Http\Controllers\CP\CpController;

/**
 * The Send It landing page: which channels are ready, what's scheduled, and
 * how recent scheduled sends went.
 */
class OverviewController extends CpController
{
    /** @var array<string, string> */
    protected array $channelLabels = [];

    public function __invoke(ChannelManager $channels, ScheduleStore $schedule, DeviceStore $devices)
    {
        $this->authorize('view send it');

        $this->channelLabels = collect($channels->all())->map(fn (Channel $channel) => $channel->label())->all();

        $sends = collect($schedule->all())->sortByDesc(fn (ScheduledSend $send) => $send->sendAt);

        return view('send-it::cp.index', [
            'title' => __('Send It'),
            'channels' => collect($channels->all())->map(fn (Channel $channel) => [
                'label' => $channel->label(),
                'configured' => $channel->isConfigured(),
                'default' => $channel->key() === $channels->default(),
            ])->values()->all(),
            'devices' => config('send-it.channels.apns.enabled') ? $devices->count() : null,
            'upcoming' => $sends->filter->isPending()->reverse()->map(fn ($send) => $this->row($send))->values()->all(),
            'recent' => $sends->reject->isPending()->take(25)->map(fn ($send) => $this->row($send))->values()->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(ScheduledSend $send): array
    {
        $entry = Entry::find($send->entry);

        return [
            'title' => $entry?->get('title') ?? $send->entry,
            'url' => $entry?->editUrl(),
            'channel' => $this->channelLabels[$send->channel] ?? $send->channel,
            'send_at' => $send->sendAt->setTimezone(config('app.timezone')),
            'processed_at' => $send->processedAt?->setTimezone(config('app.timezone')),
            'status' => $send->status,
            'message' => $send->message,
        ];
    }
}
