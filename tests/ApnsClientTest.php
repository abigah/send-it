<?php

namespace Abigah\SendIt\Tests;

use Abigah\SendIt\Push\ApnsClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class ApnsClientTest extends TestCase
{
    private string $privateKey = '';

    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $this->privateKey);
        $this->publicKey = openssl_pkey_get_details($key)['key'];
    }

    private function client(): ApnsClient
    {
        return new ApnsClient('TEAM123456', 'KEY1234567', $this->privateKey, 'com.example.app', 2);
    }

    public function test_it_makes_a_valid_es256_provider_token(): void
    {
        $jwt = $this->client()->makeToken(1_700_000_000);
        [$header, $claims, $signature] = explode('.', $jwt);

        $this->assertSame(['alg' => 'ES256', 'kid' => 'KEY1234567'], json_decode($this->decode($header), true));
        $this->assertSame(['iss' => 'TEAM123456', 'iat' => 1_700_000_000], json_decode($this->decode($claims), true));

        $raw = $this->decode($signature);
        $this->assertSame(64, strlen($raw));
        $this->assertSame(1, openssl_verify("{$header}.{$claims}", $this->joseToDer($raw), $this->publicKey, OPENSSL_ALGO_SHA256));
    }

    public function test_it_sends_to_the_right_host_with_apns_headers(): void
    {
        Http::fake([
            'api.sandbox.push.apple.com/*' => Http::response('', 200),
        ]);

        $token = str_repeat('ab', 32);
        $results = $this->client()->send([$token], ['aps' => ['alert' => ['title' => 'Hi']]], 'sandbox');

        $this->assertSame(['status' => 200, 'reason' => null], $results[$token]);

        Http::assertSent(function (Request $request) use ($token) {
            return $request->url() === "https://api.sandbox.push.apple.com/3/device/{$token}"
                && $request->header('apns-topic') === ['com.example.app']
                && $request->header('apns-push-type') === ['alert']
                && str_starts_with($request->header('Authorization')[0], 'Bearer ')
                && $request['aps']['alert']['title'] === 'Hi';
        });
    }

    public function test_it_reports_apns_rejection_reasons(): void
    {
        $good = str_repeat('aa', 32);
        $bad = str_repeat('bb', 32);

        Http::fake([
            "api.push.apple.com/3/device/{$good}" => Http::response('', 200),
            "api.push.apple.com/3/device/{$bad}" => Http::response(['reason' => 'Unregistered'], 410),
        ]);

        $results = $this->client()->send([$good, $bad, $good], ['aps' => []]);

        $this->assertCount(2, $results);
        $this->assertSame(200, $results[$good]['status']);
        $this->assertSame(['status' => 410, 'reason' => 'Unregistered'], $results[$bad]);
    }

    private function decode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4));
    }

    private function joseToDer(string $raw): string
    {
        $int = function (string $bytes): string {
            $bytes = ltrim($bytes, "\x00");
            if (ord($bytes[0]) > 0x7F) {
                $bytes = "\x00".$bytes;
            }

            return "\x02".chr(strlen($bytes)).$bytes;
        };

        $body = $int(substr($raw, 0, 32)).$int(substr($raw, 32));

        return "\x30".chr(strlen($body)).$body;
    }
}
