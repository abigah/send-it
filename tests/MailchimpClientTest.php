<?php

namespace Abigah\SendIt\Tests;

use Abigah\SendIt\Mailchimp\MailchimpClient;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class MailchimpClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function client(): MailchimpClient
    {
        return new MailchimpClient('key-us3', 'us3');
    }

    public function test_it_creates_a_campaign_for_the_whole_audience(): void
    {
        Http::fake([
            '*/campaigns' => Http::response(['id' => 'abc', 'web_id' => 1], 200),
        ]);

        $this->client()->createCampaign('list123', ['subject_line' => 'Hi', 'from_name' => 'Abigah', 'reply_to' => 'hi@example.com']);

        Http::assertSent(function ($request) {
            return $request['recipients'] === ['list_id' => 'list123']
                && ! isset($request['recipients']['segment_opts']);
        });
    }

    public function test_a_missing_sender_comes_from_the_audience_defaults(): void
    {
        Http::fake([
            '*/lists/list123*' => Http::response(['campaign_defaults' => ['from_name' => 'Pray for 10', 'from_email' => 'hello@example.com']], 200),
            '*/campaigns' => Http::response(['id' => 'abc', 'web_id' => 1], 200),
        ]);

        $this->client()->createCampaign('list123', ['subject_line' => 'Hi', 'from_name' => null, 'reply_to' => '']);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/campaigns')
            && $request['settings'] === ['subject_line' => 'Hi', 'from_name' => 'Pray for 10', 'reply_to' => 'hello@example.com']);
    }

    public function test_a_configured_sender_skips_the_audience_lookup(): void
    {
        Http::fake([
            '*/campaigns' => Http::response(['id' => 'abc', 'web_id' => 1], 200),
        ]);

        $this->client()->createCampaign('list123', ['subject_line' => 'Hi', 'from_name' => 'Abigah', 'reply_to' => 'hi@example.com']);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['settings']['from_name'] === 'Abigah');
    }

    public function test_it_creates_a_campaign_targeting_a_tag_segment(): void
    {
        Http::fake([
            '*/campaigns' => Http::response(['id' => 'abc', 'web_id' => 1], 200),
        ]);

        $this->client()->createCampaign(
            'list123',
            ['subject_line' => 'Hi', 'from_name' => 'Abigah', 'reply_to' => 'hi@example.com'],
            ['saved_segment_id' => 456],
        );

        Http::assertSent(function ($request) {
            return $request['recipients'] === [
                'list_id' => 'list123',
                'segment_opts' => ['saved_segment_id' => 456],
            ];
        });
    }

    public function test_it_sends_a_campaign_test(): void
    {
        Http::fake([
            '*/campaigns/abc/actions/test' => Http::response(null, 204),
        ]);

        $this->client()->sendTest('abc', ['mike@example.com', 'ann@example.com']);

        Http::assertSent(fn ($request) => $request->url() === 'https://us3.api.mailchimp.com/3.0/campaigns/abc/actions/test'
            && $request['test_emails'] === ['mike@example.com', 'ann@example.com']
            && $request['send_type'] === 'html');
    }

    public function test_it_lists_audience_tags(): void
    {
        Http::fake([
            '*/lists/list123/segments*' => Http::response([
                'segments' => [
                    ['id' => 1, 'name' => 'Volunteers', 'member_count' => 12],
                    ['id' => 2, 'name' => 'Lapsed donors', 'member_count' => 3],
                ],
            ], 200),
        ]);

        $tags = $this->client()->listTags('list123');

        $this->assertSame([
            ['id' => 1, 'name' => 'Volunteers', 'member_count' => 12],
            ['id' => 2, 'name' => 'Lapsed donors', 'member_count' => 3],
        ], $tags);
    }
}
