<?php

namespace Abigah\SendIt\Tests;

use Abigah\SendIt\Actions\SendIt;
use Abigah\SendIt\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Orchestra\Testbench\TestCase;
use Statamic\Providers\StatamicServiceProvider;

class SendItFieldsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [StatamicServiceProvider::class, ServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);

        $app['config']->set('send-it.channels.mailchimp', array_merge($app['config']->get('send-it.channels.mailchimp'), [
            'enabled' => true, 'api_key' => 'key-us1', 'audience_id' => 'list',
        ]));
        $app['config']->set('send-it.channels.apns', array_merge($app['config']->get('send-it.channels.apns'), [
            'enabled' => true, 'team_id' => 'TEAM', 'key_id' => 'KEY', 'topic' => 'com.example.app', 'private_key' => $pem,
        ]));
    }

    /**
     * @return array<int, string> the fields that failed
     */
    private function errors(array $values): array
    {
        try {
            (new SendIt)->fields()->addValues($values)->validate();

            return [];
        } catch (ValidationException $e) {
            return array_keys($e->errors());
        }
    }

    public function test_only_the_chosen_channels_fields_are_required(): void
    {
        $this->assertSame([], $this->errors(['channel' => 'apns', 'apns_delivery' => 'send']));
        $this->assertSame([], $this->errors(['channel' => 'mailchimp', 'mailchimp_delivery' => 'draft']));
        $this->assertSame([], $this->errors(['channel' => 'mailer']));

        $this->assertSame(['apns_delivery'], $this->errors(['channel' => 'apns']));
        $this->assertSame(['mailchimp_delivery'], $this->errors(['channel' => 'mailchimp']));
    }

    public function test_hidden_values_left_on_another_channel_are_ignored(): void
    {
        $this->assertSame([], $this->errors([
            'channel' => 'apns',
            'apns_delivery' => 'send',
            'mailchimp_delivery' => 'schedule',
        ]));

        $this->assertSame([], $this->errors([
            'channel' => 'mailchimp',
            'mailchimp_delivery' => 'draft',
            'apns_delivery' => 'schedule',
        ]));
    }

    public function test_a_schedule_needs_a_time(): void
    {
        $this->assertSame(['apns_schedule_at'], $this->errors(['channel' => 'apns', 'apns_delivery' => 'schedule']));
        $this->assertSame(['mailchimp_schedule_at'], $this->errors(['channel' => 'mailchimp', 'mailchimp_delivery' => 'schedule']));
    }
}
