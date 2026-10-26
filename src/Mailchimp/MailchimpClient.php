<?php

namespace Abigah\SendIt\Mailchimp;

use Abigah\SendIt\Exceptions\SendItException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Thin wrapper around the Mailchimp Marketing API (v3).
 *
 * @see https://mailchimp.com/developer/marketing/api/
 */
class MailchimpClient
{
    public function __construct(
        protected string $apiKey,
        protected ?string $serverPrefix = null,
    ) {
        $this->serverPrefix = $serverPrefix ?: $this->deriveServerPrefix($apiKey);
    }

    /**
     * Create a regular campaign and return the API response.
     *
     * Campaigns made through the API don't pick up the audience's default
     * sender, so a missing from_name or reply_to is filled in from it.
     *
     * @param  array<string, mixed>  $settings  Mailchimp campaign settings.
     * @param  array<string, mixed>  $segmentOpts  Optional segment_opts to
     *                                             target a tag/segment within
     *                                             the audience.
     * @return array<string, mixed>
     */
    public function createCampaign(string $audienceId, array $settings, array $segmentOpts = []): array
    {
        $recipients = ['list_id' => $audienceId];

        if ($segmentOpts !== []) {
            $recipients['segment_opts'] = $segmentOpts;
        }

        $settings = array_filter($settings, fn ($value) => $value !== null && $value !== '');

        if (! isset($settings['from_name'], $settings['reply_to'])) {
            $defaults = $this->campaignDefaults($audienceId);

            $settings += array_filter([
                'from_name' => $defaults['from_name'] ?? null,
                'reply_to' => $defaults['from_email'] ?? null,
            ]);
        }

        return $this->request()
            ->post('/campaigns', [
                'type' => 'regular',
                'recipients' => $recipients,
                'settings' => $settings,
            ])
            ->throw()
            ->json();
    }

    /**
     * The audience's default sender, as set in Mailchimp.
     *
     * @return array<string, mixed>
     */
    public function campaignDefaults(string $audienceId): array
    {
        return $this->request()
            ->get("/lists/{$audienceId}", ['fields' => 'campaign_defaults'])
            ->throw()
            ->json('campaign_defaults') ?? [];
    }

    /**
     * List the audience's tags (static segments), newest-friendly for a picker.
     *
     * @return array<int, array{id: int, name: string, member_count: int|null}>
     */
    public function listTags(string $audienceId): array
    {
        $response = $this->request()
            ->get("/lists/{$audienceId}/segments", [
                'type' => 'static',
                'count' => 1000,
                'fields' => 'segments.id,segments.name,segments.member_count',
            ])
            ->throw()
            ->json();

        return collect($response['segments'] ?? [])
            ->map(fn (array $segment): array => [
                'id' => (int) $segment['id'],
                'name' => (string) $segment['name'],
                'member_count' => $segment['member_count'] ?? null,
            ])
            ->all();
    }

    /**
     * Set the HTML body of a campaign.
     *
     * @return array<string, mixed>
     */
    public function setCampaignContent(string $campaignId, string $html): array
    {
        return $this->request()
            ->put("/campaigns/{$campaignId}/content", ['html' => $html])
            ->throw()
            ->json();
    }

    /**
     * Send a campaign immediately.
     */
    public function sendCampaign(string $campaignId): void
    {
        $this->request()
            ->post("/campaigns/{$campaignId}/actions/send")
            ->throw();
    }

    /**
     * Send a test of a campaign to the given addresses.
     *
     * @param  array<int, string>  $emails
     */
    public function sendTest(string $campaignId, array $emails): void
    {
        $this->request()
            ->post("/campaigns/{$campaignId}/actions/test", [
                'test_emails' => array_values($emails),
                'send_type' => 'html',
            ])
            ->throw();
    }

    protected function request(): PendingRequest
    {
        if (! $this->serverPrefix) {
            throw new SendItException(
                'Unable to determine the Mailchimp server prefix. Set SEND_IT_MAILCHIMP_SERVER_PREFIX.'
            );
        }

        return Http::baseUrl("https://{$this->serverPrefix}.api.mailchimp.com/3.0")
            ->withBasicAuth('anystring', $this->apiKey)
            ->acceptJson()
            ->asJson();
    }

    protected function deriveServerPrefix(string $apiKey): ?string
    {
        return Str::contains($apiKey, '-')
            ? Str::afterLast($apiKey, '-')
            : null;
    }
}
