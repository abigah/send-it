<?php

namespace Abigah\SendIt;

use Abigah\SendIt\Actions\SendIt;
use Abigah\SendIt\Channels\ChannelManager;
use Abigah\SendIt\Channels\MailchimpChannel;
use Abigah\SendIt\Channels\MailerChannel;
use Abigah\SendIt\Mailchimp\MailchimpClient;
use Illuminate\Contracts\Container\Container;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $actions = [
        SendIt::class,
    ];

    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/send-it.php', 'send-it');

        $this->app->singleton(ChannelManager::class, function (Container $app) {
            $manager = new ChannelManager($app, config('send-it.default'));

            $this->registerChannels($manager);

            return $manager;
        });
    }

    public function bootAddon()
    {
        $this->publishes([
            __DIR__.'/../config/send-it.php' => config_path('send-it.php'),
        ], 'send-it-config');
    }

    /**
     * Register the channels defined in config. Third parties may call
     * ChannelManager::extend() from their own providers to add more.
     */
    protected function registerChannels(ChannelManager $manager): void
    {
        $channels = config('send-it.channels', []);

        if (($channels['mailchimp']['enabled'] ?? false)) {
            $manager->extend('mailchimp', function () use ($channels) {
                $config = $channels['mailchimp'];

                return new MailchimpChannel(
                    $config,
                    new MailchimpClient(
                        (string) ($config['api_key'] ?? ''),
                        $config['server_prefix'] ?? null,
                    ),
                );
            });
        }

        if (($channels['mailer']['enabled'] ?? false)) {
            $manager->extend('mailer', fn () => new MailerChannel($channels['mailer']));
        }
    }
}
