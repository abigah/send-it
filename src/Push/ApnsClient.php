<?php

namespace Abigah\SendIt\Push;

use Abigah\SendIt\Exceptions\SendItException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Sends notifications through Apple Push Notification service (APNs) using
 * token-based (.p8) authentication over HTTP/2.
 *
 * @see https://developer.apple.com/documentation/usernotifications/sending-notification-requests-to-apns
 */
class ApnsClient
{
    public const PRODUCTION = 'https://api.push.apple.com';

    public const SANDBOX = 'https://api.sandbox.push.apple.com';

    /**
     * Reasons APNs gives for tokens that will never work again. Devices with
     * these should be forgotten.
     */
    public const DEAD_TOKEN_REASONS = ['BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic'];

    /**
     * Reasons APNs gives when the provider (JWT) token itself is rejected.
     */
    public const PROVIDER_TOKEN_REASONS = ['InvalidProviderToken', 'ExpiredProviderToken'];

    public function __construct(
        protected string $teamId,
        protected string $keyId,
        protected string $privateKey,
        protected string $topic,
        protected int $concurrency = 20,
    ) {}

    /**
     * Send one payload to many device tokens in the given environment.
     *
     * @param  array<int, string>  $tokens
     * @param  array<string, mixed>  $payload
     * @return array<string, array{status: int, reason: ?string}> keyed by token
     */
    public function send(array $tokens, array $payload, string $environment = 'production'): array
    {
        $host = $environment === 'sandbox' ? self::SANDBOX : self::PRODUCTION;
        $results = $this->sendWith($this->token(), array_values(array_unique($tokens)), $payload, $host);

        // A rejected provider token (e.g. after the key changed) is dropped
        // from the cache and the affected devices retried once with a new one.
        $retry = array_keys(array_filter(
            $results,
            fn (array $result) => in_array($result['reason'], self::PROVIDER_TOKEN_REASONS, true),
        ));

        if ($retry !== []) {
            Cache::forget($this->cacheKey());

            $results = array_replace($results, $this->sendWith($this->token(), $retry, $payload, $host));
        }

        return $results;
    }

    /**
     * @param  array<int, string>  $tokens
     * @param  array<string, mixed>  $payload
     * @return array<string, array{status: int, reason: ?string}>
     */
    protected function sendWith(string $jwt, array $tokens, array $payload, string $host): array
    {
        $results = [];

        foreach (array_chunk($tokens, max(1, $this->concurrency)) as $batch) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $token) => $pool->as($token)
                    ->withOptions(['version' => 2.0])
                    ->withToken($jwt)
                    ->withHeaders([
                        'apns-topic' => $this->topic,
                        'apns-push-type' => 'alert',
                        'apns-priority' => '10',
                    ])
                    ->timeout(15)
                    ->post("{$host}/3/device/{$token}", $payload),
                $batch,
            ));

            foreach ($batch as $token) {
                $results[$token] = $this->result($responses[$token] ?? null);
            }
        }

        return $results;
    }

    /**
     * The provider authentication token. APNs rejects tokens older than an
     * hour and throttles ones refreshed more often than every 20 minutes, so
     * cache for 45.
     */
    public function token(): string
    {
        return Cache::remember($this->cacheKey(), now()->addMinutes(45), fn () => $this->makeToken());
    }

    /**
     * Includes a fingerprint of the key, so a replaced key never reuses a
     * token signed by the old one.
     */
    protected function cacheKey(): string
    {
        return "send-it.apns.jwt.{$this->teamId}.{$this->keyId}.".substr(hash('sha256', $this->privateKey), 0, 16);
    }

    public function makeToken(?int $issuedAt = null): string
    {
        $key = openssl_pkey_get_private($this->privateKey);

        if ($key === false) {
            throw new SendItException('The APNs private key could not be read. Check the configured APNs key.');
        }

        $header = static::base64Url(json_encode(['alg' => 'ES256', 'kid' => $this->keyId]));
        $claims = static::base64Url(json_encode(['iss' => $this->teamId, 'iat' => $issuedAt ?? time()]));

        if (! openssl_sign("{$header}.{$claims}", $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new SendItException('Signing the APNs token failed.');
        }

        return "{$header}.{$claims}.".static::base64Url(static::derToJose($der));
    }

    /**
     * @return array{status: int, reason: ?string}
     */
    protected function result(mixed $response): array
    {
        if (! $response instanceof Response) {
            // Connection-level failure (timeout, DNS, TLS…).
            return ['status' => 0, 'reason' => $response instanceof \Throwable ? $response->getMessage() : 'No response'];
        }

        return [
            'status' => $response->status(),
            'reason' => $response->successful() ? null : ($response->json('reason') ?? 'HTTP '.$response->status()),
        ];
    }

    public static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * OpenSSL produces a DER-encoded ECDSA signature; JWS (ES256) wants the
     * raw 64-byte r||s concatenation.
     */
    public static function derToJose(string $der): string
    {
        $offset = 2; // SEQUENCE tag + length (P-256 signatures are always < 128 bytes)
        $parts = [];

        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$offset + 1]);
            $int = substr($der, $offset + 2, $length);
            $parts[] = str_pad(ltrim($int, "\x00"), 32, "\x00", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $parts[0].$parts[1];
    }
}
