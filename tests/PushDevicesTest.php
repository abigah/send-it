<?php

namespace Abigah\SendIt\Tests;

use Abigah\SendIt\Jobs\SendPushNotification;
use Abigah\SendIt\Push\ApnsClient;
use Abigah\SendIt\Push\DeviceStore;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class PushDevicesTest extends TestCase
{
    protected string $storePath;

    protected function defineEnvironment($app): void
    {
        $this->storePath = sys_get_temp_dir().'/send-it-devices-'.uniqid().'.json';

        $app['config']->set('send-it.channels.apns.devices', [
            'route' => 'api/push/devices',
            'middleware' => ['api'],
            'store' => $this->storePath,
        ]);
        $app->singleton(DeviceStore::class, fn () => new DeviceStore($this->storePath));
    }

    protected function tearDown(): void
    {
        @unlink($this->storePath);

        parent::tearDown();
    }

    protected function devices(): DeviceStore
    {
        return $this->app->make(DeviceStore::class);
    }

    protected function defineRoutes($router): void
    {
        require __DIR__.'/../routes/push.php';
    }

    public function test_an_app_can_register_its_device_token(): void
    {
        $token = str_repeat('AB', 32);

        $this->postJson('api/push/devices', [
            'token' => $token,
            'platform' => 'ios',
            'environment' => 'sandbox',
            'app_version' => '1.0',
        ])->assertCreated();

        // Registering again updates rather than duplicates.
        $this->postJson('api/push/devices', ['token' => $token, 'environment' => 'production'])->assertOk();

        $this->assertSame(1, $this->devices()->count());
        $this->assertSame('production', $this->devices()->environment($token));
        $this->assertArrayHasKey(strtolower($token), $this->devices()->all());
    }

    public function test_it_rejects_malformed_tokens(): void
    {
        $this->postJson('api/push/devices', ['token' => 'not-a-token'])->assertUnprocessable();
        $this->assertSame(0, $this->devices()->count());
    }

    public function test_an_app_can_unregister(): void
    {
        $token = str_repeat('cd', 32);
        $this->devices()->register($token, []);

        $this->deleteJson("api/push/devices/{$token}")->assertNoContent();
        $this->assertSame(0, $this->devices()->count());
    }

    public function test_the_job_sends_to_each_environment_and_forgets_dead_tokens(): void
    {
        $live = str_repeat('11', 32);
        $dead = str_repeat('22', 32);
        $dev = str_repeat('33', 32);

        $this->devices()->register($live, ['environment' => 'production']);
        $this->devices()->register($dead, ['environment' => 'production']);
        $this->devices()->register($dev, ['environment' => 'sandbox']);

        Http::fake([
            "api.push.apple.com/3/device/{$live}" => Http::response('', 200),
            "api.push.apple.com/3/device/{$dead}" => Http::response(['reason' => 'BadDeviceToken'], 400),
            "api.sandbox.push.apple.com/3/device/{$dev}" => Http::response('', 200),
        ]);

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);

        $totals = (new SendPushNotification(['aps' => ['alert' => ['title' => 'Hi']]], 'Hi'))
            ->handle(new ApnsClient('TEAM', 'KEY', $pem, 'com.example.app'), $this->devices());

        $this->assertSame(['sent' => 2, 'failed' => 1, 'removed' => 1], $totals);
        $this->assertEqualsCanonicalizing([$live, $dev], array_keys($this->devices()->all()));
    }

    public function test_re_registering_the_same_device_on_the_same_day_leaves_the_file_untouched(): void
    {
        $token = str_repeat('ef', 32);
        $attributes = ['platform' => 'ios', 'environment' => 'production'];

        $this->assertTrue($this->devices()->register($token, $attributes));
        $before = file_get_contents($this->storePath);
        touch($this->storePath, time() - 60);
        clearstatcache();
        $mtime = filemtime($this->storePath);

        $this->assertFalse($this->devices()->register($token, $attributes));
        clearstatcache();

        $this->assertSame($mtime, filemtime($this->storePath));
        $this->assertSame($before, file_get_contents($this->storePath));
    }
}
