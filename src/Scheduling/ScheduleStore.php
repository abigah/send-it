<?php

namespace Abigah\SendIt\Scheduling;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Flat-file store of pending and processed scheduled sends.
 *
 * Reads and writes go through an exclusive file lock so the every-minute
 * command and a control-panel user scheduling a send never clobber each other.
 */
class ScheduleStore
{
    public function __construct(protected string $path) {}

    /**
     * @return array<int, ScheduledSend>
     */
    public function all(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        return $this->decode((string) file_get_contents($this->path));
    }

    /**
     * Pending sends whose time has arrived (or passed, if a run was missed).
     *
     * @return array<int, ScheduledSend>
     */
    public function due(CarbonImmutable $now): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (ScheduledSend $send) => $send->isDue($now),
        ));
    }

    public function add(ScheduledSend $send): void
    {
        $this->mutate(function (array $records) use ($send) {
            $records[] = $send;

            return $records;
        });
    }

    public function update(ScheduledSend $updated): void
    {
        $this->mutate(fn (array $records) => array_map(
            fn (ScheduledSend $send) => $send->id === $updated->id ? $updated : $send,
            $records,
        ));
    }

    /**
     * Atomically read, transform, and write the records under an exclusive lock.
     *
     * @param  callable(array<int, ScheduledSend>): array<int, ScheduledSend>  $callback
     */
    protected function mutate(callable $callback): void
    {
        $this->ensureDirectory();

        $handle = fopen($this->path, 'c+');

        if ($handle === false) {
            throw new RuntimeException("Unable to open the send-it schedule store at [{$this->path}].");
        }

        try {
            flock($handle, LOCK_EX);

            $records = $callback($this->decode((string) stream_get_contents($handle)));

            $json = json_encode(
                array_map(fn (ScheduledSend $send) => $send->toArray(), array_values($records)),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            );

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $json);
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return array<int, ScheduledSend>
     */
    protected function decode(string $contents): array
    {
        $data = json_decode($contents ?: '[]', true);

        if (! is_array($data)) {
            return [];
        }

        return array_map(fn (array $record) => ScheduledSend::fromArray($record), $data);
    }

    protected function ensureDirectory(): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }
}
