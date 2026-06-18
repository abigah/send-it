<?php

namespace Abigah\SendIt\Channels;

use Abigah\SendIt\Contracts\Channel;
use Abigah\SendIt\Exceptions\SendItException;
use Abigah\SendIt\Mailchimp\MailchimpClient;
use Abigah\SendIt\Support\EntryContent;
use Abigah\SendIt\Support\SendResult;
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
    ) {
    }

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
            'mailchimp_send_immediately' => [
                'type' => 'toggle',
                'display' => 'Send immediately',
                'instructions' => 'When off, the campaign is created as a draft for review in Mailchimp.',
                'default' => (bool) ($this->config['send_immediately'] ?? false),
                'if' => ['channel' => 'equals mailchimp'],
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

        try {
            $campaign = $this->client->createCampaign($audienceId, [
                'subject_line' => $subject,
                'title' => EntryContent::title($entry),
                'from_name' => $this->config['from_name'] ?? null,
                'reply_to' => $this->config['reply_to'] ?? null,
            ]);

            $this->client->setCampaignContent($campaign['id'], $html);

            $sendImmediately = (bool) ($options['mailchimp_send_immediately'] ?? $this->config['send_immediately'] ?? false);

            if ($sendImmediately) {
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
            $sendImmediately
                ? "Sent Mailchimp campaign for \"{$subject}\"."
                : "Created Mailchimp draft campaign for \"{$subject}\".",
            $entry,
            ['campaign_id' => $campaign['id'], 'web_id' => $campaign['web_id'] ?? null],
        );
    }

    protected function errorDetail(RequestException $e): string
    {
        $body = $e->response?->json() ?? [];

        return $body['detail'] ?? $e->getMessage();
    }
}
