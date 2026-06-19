<?php

namespace Abigah\SendIt;

use Abigah\SendIt\Actions\SendIt;
use Abigah\SendIt\Channels\ChannelManager;
use Abigah\SendIt\Channels\MailchimpChannel;
use Abigah\SendIt\Channels\MailerChannel;
use Abigah\SendIt\Mailchimp\MailchimpClient;
use Abigah\SendIt\Support\EmailRenderer;
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

        $this->app->singleton(EmailRenderer::class, function () {
            return new EmailRenderer(config('send-it.email', []));
        });

        $this->app->singleton(ChannelManager::class, function (Container $app) {
            $manager = new ChannelManager($app, config('send-it.default'));

            $this->registerChannels($manager);

            return $manager;
        });
    }

    public function bootAddon()
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'send-it');

        $this->publishes([
            __DIR__.'/../config/send-it.php' => config_path('send-it.php'),
        ], 'send-it-config');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/send-it'),
        ], 'send-it-views');
    }

    /**
     * Register the channels defined in config. Third parties may call
     * ChannelManager::extend() from their own providers to add more.
     */
    protected function registerChannels(ChannelManager $manager): void
    {
        $channels = config('send-it.channels', []);

        if (($channels['mailchimp']['enabled'] ?? false)) {
            $manager->extend('mailchimp', function (Container $app) use ($channels) {
                $config = $channels['mailchimp'];

                return new MailchimpChannel(
                    $config,
                    new MailchimpClient(
                        (string) ($config['api_key'] ?? ''),
                        $config['server_prefix'] ?? null,
                    ),
                    $app->make(EmailRenderer::class),
                );
            });
        }

        if (($channels['mailer']['enabled'] ?? false)) {
            $manager->extend('mailer', fn (Container $app) => new MailerChannel(
                $channels['mailer'],
                $app->make(EmailRenderer::class),
            ));
        }
    }
}
