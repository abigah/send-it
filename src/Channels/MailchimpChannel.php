<?php

namespace Abigah\SendIt\Channels;

use Abigah\SendIt\Contracts\Channel;
use Abigah\SendIt\Exceptions\SendItException;
use Abigah\SendIt\Mailchimp\MailchimpClient;
use Abigah\SendIt\Scheduling\ScheduledSend;
use Abigah\SendIt\Scheduling\ScheduleStore;
use Abigah\SendIt\Support\EmailRenderer;
use Abigah\SendIt\Support\EntryContent;
use Abigah\SendIt\Support\Greeting;
use Abigah\SendIt\Support\ScheduleTime;
use Abigah\SendIt\Support\SendResult;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Statamic\Contracts\Entries\Entry;

class MailchimpChannel implements Channel
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config,
        protected MailchimpClient $client,
        protected EmailRenderer $renderer,
        protected ScheduleStore $store,
    ) {}

    public function key(): string
    {
        return 'mailchimp';
    }

    public function label(): string
    {
        return 'Mailchimp campaign';
    }

    public function isConfigured(): bool
    {
        return ($this->config['enabled'] ?? false)
            && ! empty($this->config['api_key'])
            && ! empty($this->config['audience_id']);
    }

    public function fields(): array
    {
        return [
            'mailchimp_audience_id' => [
                'type' => 'text',
                'display' => 'Audience',
                'instructions' => 'Mailchimp audience (list) id. Leave blank to use the configured default.',
                'if' => ['channel' => 'equals mailchimp'],
            ],
            'mailchimp_delivery' => [
                'type' => 'select',
                'display' => 'Delivery',
                'instructions' => 'Save the campaign as a draft for review, send it now, or schedule it for later.',
                'options' => [
                    'draft' => 'Save as draft',
                    'send' => 'Send immediately',
                    'schedule' => 'Schedule for later',
                ],
                'placeholder' => 'Choose an option…',
                'clearable' => true,
                'validate' => 'required',
                'if' => ['channel' => 'equals mailchimp'],
            ],
            'mailchimp_tag' => [
                'type' => 'select',
                'display' => 'Tag',
                'instructions' => 'Send only to subscribers with this tag. Leave blank to send to the whole audience. Tags are listed for the default audience.',
                'options' => $this->tagOptions(),
                'clearable' => true,
                'placeholder' => 'Whole audience',
                'if' => ['channel' => 'equals mailchimp'],
            ],
            'mailchimp_schedule_at' => [
                'type' => 'date',
                'display' => 'Schedule for',
                'instructions' => 'Shown in your own timezone; stored and sent in the site timezone ('
                    .(config('app.timezone') ?: 'UTC').'). The entry is published automatically at this time.',
                'mode' => 'single',
                'time_enabled' => true,
                'validate' => 'required_if:mailchimp_delivery,schedule',
                'if' => [
                    'channel' => 'equals mailchimp',
                    'mailchimp_delivery' => 'equals schedule',
                ],
            ],
        ];
    }

    public function send(Entry $entry, array $options = []): SendResult
    {
        $audienceId = $options['mailchimp_audience_id'] ?: ($this->config['audience_id'] ?? null);

        if (empty($audienceId)) {
            throw new SendItException('No Mailchimp audience id configured for this send.');
        }

        $subject = $options['subject_line'] ?? EntryContent::title($entry);
        $delivery = $this->resolveDelivery($options);

        if ($delivery === 'schedule') {
            return $this->schedule($entry, $subject, $options);
        }

        $html = EntryContent::html($entry, $this->config['content_field'] ?? 'content');

        if ($html === '') {
            throw new SendItException("Entry [{$entry->id()}] has no content to send.");
        }

        $html = $this->renderer->render($subject, $html, array_merge(EntryContent::articleMeta($entry), [
            // Personalised greeting via the *|FNAME|* merge tag.
            'greeting' => Greeting::forMailchimp(
                config('send-it.email.greeting'),
                config('send-it.email.greeting_fallback', 'friend'),
            ),
            // Mailchimp requires these merge tags in campaign content; it
            // replaces them with the recipient's real links on send.
            'unsubscribe_url' => '*|UNSUB|*',
            'update_preferences_url' => '*|UPDATE_PROFILE|*',
        ]));

        try {
            $campaign = $this->client->createCampaign($audienceId, [
                'subject_line' => $subject,
                'title' => EntryContent::title($entry),
                'from_name' => $this->config['from_name'] ?? null,
                'reply_to' => $this->config['reply_to'] ?? null,
            ], $this->segmentOpts($options));

            $this->client->setCampaignContent($campaign['id'], $html);

            if ($delivery === 'send') {
                $this->client->sendCampaign($campaign['id']);
            }
        } catch (RequestException $e) {
            return SendResult::failure(
                $this->key(),
                "Mailchimp rejected the campaign: {$this->errorDetail($e)}",
                $entry,
            );
        }

        return SendResult::success(
            $this->key(),
            $delivery === 'send'
                ? "Sent Mailchimp campaign for \"{$subject}\"."
                : "Created Mailchimp draft campaign for \"{$subject}\".",
            $entry,
            ['campaign_id' => $campaign['id'], 'web_id' => $campaign['web_id'] ?? null],
        );
    }

    /**
     * Persist the send for the every-minute scheduler to pick up. The actual
     * campaign is created and sent when send-it:run-scheduled fires, so the
     * entry's latest content is used and it can be published at that moment.
     *
     * @param  array<string, mixed>  $options
     */
    protected function schedule(Entry $entry, string $subject, array $options): SendResult
    {
        $sendAt = ScheduleTime::resolve(
            (string) ($options['mailchimp_schedule_at'] ?? ''),
            config('app.timezone') ?: 'UTC',
        );

        // Replay as an immediate send when the scheduler runs it.
        $options['mailchimp_delivery'] = 'send';
        unset($options['mailchimp_schedule_at']);

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
                'Scheduled "%s" to send on %s.',
                $subject,
                $sendAt->setTimezone(config('app.timezone') ?: 'UTC')->format('M j, Y g:i A T'),
            ),
            $entry,
            ['scheduled_at' => $sendAt->toIso8601String()],
        );
    }

    /**
     * Determine the delivery mode, falling back to the previous toggle and the
     * configured default for backward compatibility.
     *
     * @param  array<string, mixed>  $options
     */
    protected function resolveDelivery(array $options): string
    {
        $delivery = $options['mailchimp_delivery'] ?? null;

        if (in_array($delivery, ['draft', 'send', 'schedule'], true)) {
            return $delivery;
        }

        if (array_key_exists('mailchimp_send_immediately', $options)) {
            return $options['mailchimp_send_immediately'] ? 'send' : 'draft';
        }

        return ($this->config['send_immediately'] ?? false) ? 'send' : 'draft';
    }

    /**
     * Build the campaign's segment_opts from the chosen tag, if any.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function segmentOpts(array $options): array
    {
        $tag = $options['mailchimp_tag'] ?? null;

        return empty($tag) ? [] : ['saved_segment_id' => (int) $tag];
    }

    /**
     * Tag options for the send form, keyed by segment id. Cached briefly so we
     * don't hit the Mailchimp API on every action panel load, and fails soft
     * to an empty list when the API is unreachable or unconfigured.
     *
     * @return array<int|string, string>
     */
    protected function tagOptions(): array
    {
        $audienceId = $this->config['audience_id'] ?? null;

        if (empty($audienceId)) {
            return [];
        }

        try {
            $tags = Cache::remember(
                "send-it.mailchimp.tags.{$audienceId}",
                now()->addMinutes(5),
                fn () => $this->client->listTags($audienceId),
            );
        } catch (\Throwable $e) {
            return [];
        }

        return collect($tags)
            ->mapWithKeys(fn (array $tag): array => [
                $tag['id'] => $tag['member_count'] !== null
                    ? "{$tag['name']} ({$tag['member_count']})"
                    : $tag['name'],
            ])
            ->all();
    }

    protected function errorDetail(RequestException $e): string
    {
        $body = $e->response?->json() ?? [];

        return $body['detail'] ?? $e->getMessage();
    }
}
