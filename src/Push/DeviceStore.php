<?php

namespace Abigah\SendIt\Push;

use RuntimeException;

/**
 * Flat-file store of app installs that can receive Apple push notifications,
 * keyed by device token.
 *
 * Writes go through an exclusive file lock so concurrent registrations and a
 * running fan-out never clobber each other. A device is only rewritten when
 * something changes (last_seen is a date), so the file stays quiet in git.
 */
class DeviceStore
{
    public function __construct(protected string $path) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        return $this->decode((string) file_get_contents($this->path));
    }

    public function count(): int
    {
        return count($this->all());
    }

    public function environment(string $token): ?string
    {
        return $this->all()[strtolower($token)]['environment'] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function tokens(string $environment): array
    {
        return array_keys(array_filter(
            $this->all(),
            fn (array $device) => ($device['environment'] ?? 'production') === $environment,
        ));
    }

    /**
     * Add or update a device. Returns true when the device is new.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function register(string $token, array $attributes): bool
    {
        $token = strtolower($token);
        $created = false;

        $this->mutate(function (array $devices) use ($token, $attributes, &$created) {
            $created = ! isset($devices[$token]);

            $device = array_merge($devices[$token] ?? ['registered' => now()->toDateString()], $attributes, [
                'last_seen' => now()->toDateString(),
            ]);

            if (($devices[$token] ?? null) === $device) {
                return null;
            }

            $devices[$token] = $device;

            return $devices;
        });

        return $created;
    }

    /**
     * Remove devices. Returns how many were removed.
     *
     * @param  string|array<int, string>  $tokens
     */
    public function forget(string|array $tokens): int
    {
        $tokens = array_map('strtolower', (array) $tokens);
        $removed = 0;

        $this->mutate(function (array $devices) use ($tokens, &$removed) {
            $remaining = array_diff_key($devices, array_flip($tokens));
            $removed = count($devices) - count($remaining);

            return $removed > 0 ? $remaining : null;
        });

        return $removed;
    }

    /**
     * Atomically read, transform and write the devices under an exclusive
     * lock. The callback returns null to leave the file untouched.
     *
     * @param  callable(array<string, array<string, mixed>>): ?array<string, array<string, mixed>>  $callback
     */
    protected function mutate(callable $callback): void
    {
        $this->ensureDirectory();

        $handle = fopen($this->path, 'c+');

        if ($handle === false) {
            throw new RuntimeException("Unable to open the send-it device store at [{$this->path}].");
        }

        try {
            flock($handle, LOCK_EX);

            $devices = $callback($this->decode((string) stream_get_contents($handle)));

            if ($devices === null) {
                return;
            }

            ksort($devices);

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($devices ?: new \stdClass, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function decode(string $contents): array
    {
        $data = json_decode($contents ?: '{}', true);

        return is_array($data) ? $data : [];
    }

    protected function ensureDirectory(): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }
}
