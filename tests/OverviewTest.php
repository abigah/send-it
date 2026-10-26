<?php

namespace Abigah\SendIt\Tests;

use Abigah\SendIt\Scheduling\ScheduledSend;
use Abigah\SendIt\Scheduling\ScheduleStore;
use Abigah\SendIt\ServiceProvider;
use Carbon\CarbonImmutable;
use Statamic\CP\Navigation\Nav as Navigation;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\User;
use Statamic\Testing\AddonTestCase;

class OverviewTest extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;

    protected string $storePath;

    protected Navigation $nav;

    protected function setUp(): void
    {
        // AddonTestCase stubs Nav::build(), so keep hold of the real navigation first.
        $this->afterApplicationCreated(fn () => $this->nav = Nav::getFacadeRoot());

        parent::setUp();
    }

    protected function defineEnvironment($app): void
    {
        $this->storePath = sys_get_temp_dir().'/send-it-schedule-'.uniqid().'.json';

        $app['config']->set('send-it.schedule.store', $this->storePath);
        $app['config']->set('statamic.editions.pro', true);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    }

    protected function tearDown(): void
    {
        @unlink($this->storePath);

        parent::tearDown();
    }

    public function test_the_overview_lists_scheduled_and_processed_sends(): void
    {
        $store = $this->app->make(ScheduleStore::class);
        $store->add(new ScheduledSend('a', 'missing-entry', 'mailchimp', [], CarbonImmutable::now()->addDay()));
        $store->add((new ScheduledSend('b', 'gone-entry', 'apns', [], CarbonImmutable::now()->subDay()))
            ->markedAs('failed', 'No devices', CarbonImmutable::now()));

        $this->actingAs(User::make()->id('admin')->email('admin@example.com')->makeSuper())
            ->get(cp_route('send-it.index'))
            ->assertOk()
            ->assertSee('Send It')
            ->assertSee('Mailchimp')
            ->assertSee('missing-entry')
            ->assertSee('gone-entry')
            ->assertSee('Failed');
    }

    public function test_users_without_permission_cannot_see_it(): void
    {
        $this->actingAs(User::make()->id('editor')->email('editor@example.com'))
            ->get(cp_route('send-it.index'))
            ->assertRedirect()
            ->assertDontSee('Recent scheduled sends');
    }

    public function test_it_adds_a_tools_nav_item(): void
    {
        Nav::swap($this->nav);

        $this->actingAs(User::make()->id('admin')->email('admin@example.com')->makeSuper());

        $items = Nav::build()->pluck('items', 'display')->get('Tools');

        $this->assertContains('Send It', collect($items)->map->display()->all());
    }
}
