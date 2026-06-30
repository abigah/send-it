<?php

namespace Abigah\SendIt\Scheduling;

use Carbon\CarbonImmutable;

/**
 * A single newsletter send queued for a future time, persisted by the
 * ScheduleStore and processed by the send-it:run-scheduled command.
 */
class ScheduledSend
{
    /**
     * @param  array<string, mixed>  $options  The action-form values, replayed at send time.
     */
    public function __construct(
        public string $id,
        public string $entry,
        public string $channel,
        public array $options,
        public CarbonImmutable $sendAt,
        public string $status = 'pending',
        public ?string $message = null,
        public ?CarbonImmutable $createdAt = null,
        public ?CarbonImmutable $processedAt = null,
    ) {}

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isDue(CarbonImmutable $now): bool
    {
        return $this->isPending() && $this->sendAt->lessThanOrEqualTo($now);
    }

    public function markedAs(string $status, ?string $message, CarbonImmutable $processedAt): self
    {
        $clone = clone $this;
        $clone->status = $status;
        $clone->message = $message;
        $clone->processedAt = $processedAt;

        return $clone;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'entry' => $this->entry,
            'channel' => $this->channel,
            'options' => $this->options,
            'send_at' => $this->sendAt->utc()->toIso8601String(),
            'status' => $this->status,
            'message' => $this->message,
            'created_at' => $this->createdAt?->utc()->toIso8601String(),
            'processed_at' => $this->processedAt?->utc()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            entry: $data['entry'],
            channel: $data['channel'],
            options: $data['options'] ?? [],
            sendAt: CarbonImmutable::parse($data['send_at'])->utc(),
            status: $data['status'] ?? 'pending',
            message: $data['message'] ?? null,
            createdAt: isset($data['created_at']) ? CarbonImmutable::parse($data['created_at'])->utc() : null,
            processedAt: isset($data['processed_at']) ? CarbonImmutable::parse($data['processed_at'])->utc() : null,
        );
    }
}
