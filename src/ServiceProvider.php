<?php

namespace Abigah\SendIt;

use Abigah\SendIt\Actions\SendIt;
use Abigah\SendIt\Channels\ApnsChannel;
use Abigah\SendIt\Channels\ChannelManager;
use Abigah\SendIt\Channels\MailchimpChannel;
use Abigah\SendIt\Channels\MailerChannel;
use Abigah\SendIt\Console\Commands\RunScheduledSends;
use Abigah\SendIt\Mailchimp\MailchimpClient;
use Abigah\SendIt\Push\ApnsClient;
use Abigah\SendIt\Scheduling\ScheduleStore;
use Abigah\SendIt\Support\EmailRenderer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Container\Container;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $actions = [
        SendIt::class,
    ];

    protected $commands = [
        RunScheduledSends::class,
    ];

    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/send-it.php', 'send-it');

        $this->app->singleton(EmailRenderer::class, function () {
            return new EmailRenderer(config('send-it.email', []));
        });

        $this->app->singleton(ScheduleStore::class, function () {
            return new ScheduleStore(
                config('send-it.schedule.store') ?: storage_path('app/send-it/scheduled-sends.json'),
            );
        });

        $this->app->singleton(ApnsClient::class, function () {
            $config = config('send-it.channels.apns', []);

            return new ApnsClient(
                (string) ($config['team_id'] ?? ''),
                (string) ($config['key_id'] ?? ''),
                (string) static::apnsPrivateKey($config),
                (string) ($config['topic'] ?? ''),
                (int) ($config['concurrency'] ?? 20),
            );
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

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (config('send-it.channels.apns.enabled') && config('send-it.channels.apns.devices.route')) {
            $this->loadRoutesFrom(__DIR__.'/../routes/push.php');
        }

        $this->publishes([
            __DIR__.'/../config/send-it.php' => config_path('send-it.php'),
        ], 'send-it-config');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/send-it'),
        ], 'send-it-views');
    }

    /**
     * Process due scheduled sends once a minute. Statamic calls this when
     * running in the console; ensure `schedule:run` is wired into cron.
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('send-it:run-scheduled')
            ->everyMinute()
            ->withoutOverlapping();
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
                    $app->make(ScheduleStore::class),
                );
            });
        }

        if (($channels['mailer']['enabled'] ?? false)) {
            $manager->extend('mailer', fn (Container $app) => new MailerChannel(
                $channels['mailer'],
                $app->make(EmailRenderer::class),
            ));
        }

        if (($channels['apns']['enabled'] ?? false)) {
            $manager->extend('apns', fn (Container $app) => new ApnsChannel(
                $channels['apns'],
                $app->make(ScheduleStore::class),
                static::apnsPrivateKey($channels['apns']) ? $app->make(ApnsClient::class) : null,
            ));
        }
    }

    /**
     * The .p8 key from the inline env value (with literal "\n" allowed) or
     * from the configured file path.
     *
     * @param  array<string, mixed>  $config
     */
    protected static function apnsPrivateKey(array $config): ?string
    {
        if (! empty($config['private_key'])) {
            return str_replace('\\n', "\n", (string) $config['private_key']);
        }

        if (! empty($config['private_key_base64'])) {
            $decoded = base64_decode((string) $config['private_key_base64'], true);

            return $decoded === false ? null : $decoded;
        }

        $path = $config['private_key_path'] ?? null;

        if ($path && ! str_starts_with($path, '/')) {
            $path = base_path($path);
        }

        return $path && is_readable($path) ? (string) file_get_contents($path) : null;
    }
}
