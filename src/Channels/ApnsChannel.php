<?php

namespace Abigah\SendIt\Channels;

use Abigah\SendIt\Contracts\Channel;
use Abigah\SendIt\Exceptions\SendItException;
use Abigah\SendIt\Jobs\SendPushNotification;
use Abigah\SendIt\Push\ApnsClient;
use Abigah\SendIt\Push\PushDevice;
use Abigah\SendIt\Scheduling\ScheduledSend;
use Abigah\SendIt\Scheduling\ScheduleStore;
use Abigah\SendIt\Support\EntryContent;
use Abigah\SendIt\Support\ScheduleTime;
use Abigah\SendIt\Support\SendResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Statamic\Contracts\Entries\Entry;

/**
 * Sends an entry as an Apple push notification to every app install that has
 * registered its device token (see the devices route).
 */
class ApnsChannel implements Channel
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config,
        protected ScheduleStore $store,
        protected ?ApnsClient $client = null,
    ) {}

    public function key(): string
    {
        return 'apns';
    }

    public function label(): string
    {
        return 'Apple push notification';
    }

    public function isConfigured(): bool
    {
        return ($this->config['enabled'] ?? false)
            && ! empty($this->config['team_id'])
            && ! empty($this->config['key_id'])
            && ! empty($this->config['topic'])
            && $this->client !== null;
    }

    public function fields(): array
    {
        return [
            'apns_body' => [
                'type' => 'textarea',
                'display' => 'Message',
                'instructions' => 'Shown under the title (the Subject, or the entry title). Leave blank to use the entry\'s '
                    .($this->config['body_field'] ?? 'excerpt').' field or the start of its content. Keep it short — about 150 characters shows in full.',
                'character_limit' => 240,
                'if' => ['channel' => 'equals apns'],
            ],
            'apns_open' => [
                'type' => 'select',
                'display' => 'When tapped',
                'options' => array_merge(
                    ['entry' => 'Open this entry’s page', 'app' => 'Just open the app'],
                    $this->config['actions'] ?? [],
                ),
                'default' => 'entry',
                'if' => ['channel' => 'equals apns'],
            ],
            'apns_delivery' => [
                'type' => 'select',
                'display' => 'Delivery',
                'instructions' => 'Send a test to one device first, send to everyone now, or schedule it for later.',
                'options' => [
                    'test' => 'Test: send to one device',
                    'send' => 'Send to all devices now',
                    'schedule' => 'Schedule for later',
                ],
                'placeholder' => 'Choose an option…',
                'clearable' => true,
                'validate' => 'required',
                'if' => ['channel' => 'equals apns'],
            ],
            'apns_test_token' => [
                'type' => 'text',
                'display' => 'Test device token',
                'instructions' => 'The device token to send the test to. Leave blank to use SEND_IT_APNS_TEST_TOKEN.',
                'if' => ['channel' => 'equals apns', 'apns_delivery' => 'equals test'],
            ],
            'apns_schedule_at' => [
                'type' => 'date',
                'display' => 'Schedule for',
                'instructions' => 'Shown in your own timezone; stored and sent in the site timezone ('
                    .(config('app.timezone') ?: 'UTC').'). The entry is published automatically at this time.',
                'mode' => 'single',
                'time_enabled' => true,
                'validate' => 'required_if:apns_delivery,schedule',
                'if' => ['channel' => 'equals apns', 'apns_delivery' => 'equals schedule'],
            ],
        ];
    }

    public function send(Entry $entry, array $options = []): SendResult
    {
        $title = ($options['subject_line'] ?? null) ?: EntryContent::title($entry);
        $delivery = $options['apns_delivery'] ?? 'send';

        if ($delivery === 'schedule') {
            return $this->schedule($entry, $title, $options);
        }

        $payload = $this->payload($entry, $title, $options);

        if ($delivery === 'test') {
            return $this->sendTest($entry, $payload, $options);
        }

        if (($options['apns_open'] ?? 'entry') === 'entry' && ! $entry->published()) {
            throw new SendItException('Publish the entry before sending — the notification links to its page. (Scheduled sends publish it automatically.)');
        }

        $devices = PushDevice::count();

        if ($devices === 0) {
            throw new SendItException('No devices have registered for push notifications yet.');
        }

        $job = new SendPushNotification($payload, $title);

        if (! empty($this->config['queue'])) {
            $job->onQueue($this->config['queue']);
        }

        dispatch($job);

        return SendResult::success(
            $this->key(),
            sprintf('Sending "%s" to %s %s.', $title, number_format($devices), Str::plural('device', $devices)),
            $entry,
            ['devices' => $devices],
        );
    }

    /**
     * Build the APNs payload.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function payload(Entry $entry, string $title, array $options = []): array
    {
        $payload = [
            'aps' => array_filter([
                'alert' => [
                    'title' => $title,
                    'body' => $this->body($entry, $options),
                ],
                'sound' => $this->config['sound'] ?? 'default',
                'thread-id' => $this->config['thread_id'] ?? null,
            ]),
        ];

        $open = $options['apns_open'] ?? 'entry';

        if ($open === 'entry' && ($url = $entry->absoluteUrl())) {
            $payload['url'] = $url;
        } elseif (array_key_exists($open, $this->config['actions'] ?? [])) {
            $payload['action'] = $open;
        }

        $payload['entry'] = $entry->id();

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function body(Entry $entry, array $options): string
    {
        $custom = trim((string) ($options['apns_body'] ?? ''));

        if ($custom !== '') {
            return $custom;
        }

        $field = $this->config['body_field'] ?? 'excerpt';
        $text = $entry->blueprint()?->hasField($field) ? EntryContent::html($entry, $field) : '';

        if (trim(strip_tags($text)) === '') {
            $text = EntryContent::html($entry, $this->config['content_field'] ?? 'content');
        }

        $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5)));

        return Str::limit($text, 178);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $options
     */
    protected function sendTest(Entry $entry, array $payload, array $options): SendResult
    {
        $token = strtolower(trim((string) (($options['apns_test_token'] ?? null) ?: ($this->config['test_token'] ?? ''))));

        if ($token === '') {
            throw new SendItException('Enter a test device token (or set SEND_IT_APNS_TEST_TOKEN).');
        }

        $environment = PushDevice::where('token', $token)->value('environment')
            ?? ($this->config['test_environment'] ?? 'sandbox');

        $result = $this->client->send([$token], $payload, $environment)[$token] ?? ['status' => 0, 'reason' => 'No response'];

        return $result['status'] === 200
            ? SendResult::success($this->key(), "Sent test push ({$environment}) to …".substr($token, -8).'.', $entry)
            : SendResult::failure($this->key(), "APNs rejected the test push ({$environment}): {$result['reason']}", $entry);
    }

    /**
     * Persist the send for the every-minute scheduler, which publishes the
     * entry and replays it as an immediate send.
     *
     * @param  array<string, mixed>  $options
     */
    protected function schedule(Entry $entry, string $title, array $options): SendResult
    {
        $sendAt = ScheduleTime::resolve(
            (string) ($options['apns_schedule_at'] ?? ''),
            config('app.timezone') ?: 'UTC',
        );

        $options['apns_delivery'] = 'send';
        unset($options['apns_schedule_at']);

        $this->store->add(new ScheduledSend(
            id: (string) Str::uuid(),
            entry: $entry->id(),
            channel: $this->key(),
            options: $options,
            sendAt: $sendAt,
            createdAt: CarbonImmutable::now('UTC'),
        ));

        return SendResult::success(
            $this->key(),
            sprintf(
                'Scheduled push "%s" for %s.',
                $title,
                $sendAt->setTimezone(config('app.timezone') ?: 'UTC')->format('M j, Y g:i A T'),
            ),
            $entry,
            ['scheduled_at' => $sendAt->toIso8601String()],
        );
    }
}
