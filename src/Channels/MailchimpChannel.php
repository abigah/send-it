<?php

namespace Abigah\SendIt\Channels;

use Abigah\SendIt\Contracts\Channel;
use Abigah\SendIt\Exceptions\SendItException;
use Abigah\SendIt\Mailchimp\MailchimpClient;
use Abigah\SendIt\Support\EmailRenderer;
use Abigah\SendIt\Support\EntryContent;
use Abigah\SendIt\Support\Greeting;
use Abigah\SendIt\Support\ScheduleTime;
use Abigah\SendIt\Support\SendResult;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
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
                'default' => ($this->config['send_immediately'] ?? false) ? 'send' : 'draft',
                'clearable' => false,
                'if' => ['channel' => 'equals mailchimp'],
            ],
            'mailchimp_schedule_at' => [
                'type' => 'date',
                'display' => 'Schedule for',
                'instructions' => 'Shown in your own timezone; stored and sent in the site timezone ('
                    .(config('app.timezone') ?: 'UTC').'). Mailchimp rounds up to the next 15 minutes.',
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

        $delivery = $this->resolveDelivery($options);

        // Resolve (and validate) the schedule time before creating anything,
        // so an invalid time doesn't leave an orphaned draft behind.
        $scheduleAt = $delivery === 'schedule'
            ? ScheduleTime::forMailchimp(
                (string) ($options['mailchimp_schedule_at'] ?? ''),
                config('app.timezone') ?: 'UTC',
            )
            : null;

        try {
            $campaign = $this->client->createCampaign($audienceId, [
                'subject_line' => $subject,
                'title' => EntryContent::title($entry),
                'from_name' => $this->config['from_name'] ?? null,
                'reply_to' => $this->config['reply_to'] ?? null,
            ]);

            $this->client->setCampaignContent($campaign['id'], $html);

            if ($delivery === 'schedule') {
                $this->client->scheduleCampaign($campaign['id'], $scheduleAt);
            } elseif ($delivery === 'send') {
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
            $this->successMessage($delivery, $subject, $scheduleAt),
            $entry,
            [
                'campaign_id' => $campaign['id'],
                'web_id' => $campaign['web_id'] ?? null,
                'scheduled_at' => $scheduleAt?->toIso8601String(),
            ],
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

    protected function successMessage(string $delivery, string $subject, ?CarbonImmutable $scheduleAt): string
    {
        return match ($delivery) {
            'schedule' => sprintf(
                'Scheduled Mailchimp campaign for "%s" at %s.',
                $subject,
                $scheduleAt->setTimezone(config('app.timezone') ?: 'UTC')->format('M j, Y g:i A T'),
            ),
            'send' => "Sent Mailchimp campaign for \"{$subject}\".",
            default => "Created Mailchimp draft campaign for \"{$subject}\".",
        };
    }

    protected function errorDetail(RequestException $e): string
    {
        $body = $e->response?->json() ?? [];

        return $body['detail'] ?? $e->getMessage();
    }
}
