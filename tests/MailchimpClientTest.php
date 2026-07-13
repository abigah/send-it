<?php

namespace Abigah\SendIt\Tests;

use Abigah\SendIt\Mailchimp\MailchimpClient;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class MailchimpClientTest extends TestCase
{
    private function client(): MailchimpClient
    {
        return new MailchimpClient('key-us3', 'us3');
    }

    public function test_it_creates_a_campaign_for_the_whole_audience(): void
    {
        Http::fake([
            '*/campaigns' => Http::response(['id' => 'abc', 'web_id' => 1], 200),
        ]);

        $this->client()->createCampaign('list123', ['subject_line' => 'Hi']);

        Http::assertSent(function ($request) {
            return $request['recipients'] === ['list_id' => 'list123']
                && ! isset($request['recipients']['segment_opts']);
        });
    }

    public function test_it_creates_a_campaign_targeting_a_tag_segment(): void
    {
        Http::fake([
            '*/campaigns' => Http::response(['id' => 'abc', 'web_id' => 1], 200),
        ]);

        $this->client()->createCampaign(
            'list123',
            ['subject_line' => 'Hi'],
            ['saved_segment_id' => 456],
        );

        Http::assertSent(function ($request) {
            return $request['recipients'] === [
                'list_id' => 'list123',
                'segment_opts' => ['saved_segment_id' => 456],
            ];
        });
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
