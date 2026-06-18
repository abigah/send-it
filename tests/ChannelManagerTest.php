<?php

namespace Abigah\SendIt\Tests;

use Abigah\SendIt\Channels\ChannelManager;
use Abigah\SendIt\Contracts\Channel;
use Abigah\SendIt\Exceptions\ChannelNotFoundException;
use Abigah\SendIt\Support\SendResult;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Statamic\Contracts\Entries\Entry;

class ChannelManagerTest extends TestCase
{
    private function manager(?string $default = null): ChannelManager
    {
        return new ChannelManager(new Container, $default);
    }

    public function test_it_resolves_a_registered_channel(): void
    {
        $manager = $this->manager();
        $manager->extend('fake', fn () => $this->fakeChannel('fake', true));

        $this->assertTrue($manager->has('fake'));
        $this->assertSame('fake', $manager->channel('fake')->key());
    }

    public function test_it_resolves_the_default_channel(): void
    {
        $manager = $this->manager('fake');
        $manager->extend('fake', fn () => $this->fakeChannel('fake', true));

        $this->assertSame('fake', $manager->channel()->key());
    }

    public function test_it_throws_for_an_unknown_channel(): void
    {
        $this->expectException(ChannelNotFoundException::class);

        $this->manager()->channel('nope');
    }

    public function test_available_excludes_unconfigured_channels(): void
    {
        $manager = $this->manager();
        $manager->extend('on', fn () => $this->fakeChannel('on', true));
        $manager->extend('off', fn () => $this->fakeChannel('off', false));

        $this->assertSame(['on'], array_keys($manager->available()));
    }

    public function test_channels_are_resolved_once(): void
    {
        $manager = $this->manager();
        $manager->extend('fake', fn () => $this->fakeChannel('fake', true));

        $this->assertSame($manager->channel('fake'), $manager->channel('fake'));
    }

    private function fakeChannel(string $key, bool $configured): Channel
    {
        return new class($key, $configured) implements Channel
        {
            public function __construct(private string $key, private bool $configured)
            {
            }

            public function key(): string
            {
                return $this->key;
            }

            public function label(): string
            {
                return ucfirst($this->key);
            }

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function fields(): array
            {
                return [];
            }

            public function send(Entry $entry, array $options = []): SendResult
            {
                return SendResult::success($this->key, 'ok');
            }
        };
    }
}
