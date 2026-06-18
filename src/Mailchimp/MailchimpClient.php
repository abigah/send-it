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
     * @param  array<string, mixed>  $settings  Mailchimp campaign settings.
     * @return array<string, mixed>
     */
    public function createCampaign(string $audienceId, array $settings): array
    {
        return $this->request()
            ->post('/campaigns', [
                'type' => 'regular',
                'recipients' => ['list_id' => $audienceId],
                'settings' => array_filter($settings, fn ($value) => $value !== null && $value !== ''),
            ])
            ->throw()
            ->json();
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
