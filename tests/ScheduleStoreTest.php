<?php

namespace Abigah\SendIt\Tests;

use Abigah\SendIt\Scheduling\ScheduledSend;
use Abigah\SendIt\Scheduling\ScheduleStore;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ScheduleStoreTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/send-it-test-'.uniqid().'/scheduled.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
            @rmdir(dirname($this->path));
        }

        parent::tearDown();
    }

    private function send(string $id, CarbonImmutable $sendAt, string $status = 'pending'): ScheduledSend
    {
        return new ScheduledSend(
            id: $id,
            entry: 'entry-'.$id,
            channel: 'mailchimp',
            options: ['mailchimp_delivery' => 'send', 'subject_line' => 'Hello'],
            sendAt: $sendAt,
            status: $status,
            createdAt: CarbonImmutable::create(2026, 6, 29, 10, 0, 0, 'UTC'),
        );
    }

    public function test_it_persists_and_reloads_sends_including_options(): void
    {
        $store = new ScheduleStore($this->path);
        $store->add($this->send('a', CarbonImmutable::create(2026, 7, 1, 16, 0, 0, 'UTC')));

        $all = (new ScheduleStore($this->path))->all();

        $this->assertCount(1, $all);
        $this->assertSame('entry-a', $all[0]->entry);
        $this->assertSame('mailchimp', $all[0]->channel);
        $this->assertSame(['mailchimp_delivery' => 'send', 'subject_line' => 'Hello'], $all[0]->options);
        $this->assertSame('2026-07-01T16:00:00+00:00', $all[0]->sendAt->toIso8601String());
    }

    public function test_due_returns_only_pending_sends_at_or_before_now(): void
    {
        $store = new ScheduleStore($this->path);
        $store->add($this->send('past', CarbonImmutable::create(2026, 7, 1, 15, 0, 0, 'UTC')));
        $store->add($this->send('now', CarbonImmutable::create(2026, 7, 1, 16, 0, 0, 'UTC')));
        $store->add($this->send('future', CarbonImmutable::create(2026, 7, 1, 17, 0, 0, 'UTC')));
        $store->add($this->send('already-sent', CarbonImmutable::create(2026, 7, 1, 14, 0, 0, 'UTC'), 'sent'));

        $due = $store->due(CarbonImmutable::create(2026, 7, 1, 16, 0, 0, 'UTC'));

        $ids = array_map(fn (ScheduledSend $s) => $s->id, $due);
        sort($ids);

        $this->assertSame(['now', 'past'], $ids);
    }

    public function test_update_replaces_a_record_by_id(): void
    {
        $store = new ScheduleStore($this->path);
        $store->add($this->send('a', CarbonImmutable::create(2026, 7, 1, 16, 0, 0, 'UTC')));

        $original = $store->all()[0];
        $store->update($original->markedAs('sent', 'Done.', CarbonImmutable::create(2026, 7, 1, 16, 1, 0, 'UTC')));

        $reloaded = $store->all();

        $this->assertCount(1, $reloaded);
        $this->assertSame('sent', $reloaded[0]->status);
        $this->assertSame('Done.', $reloaded[0]->message);
        $this->assertFalse($reloaded[0]->isPending());
    }
}
