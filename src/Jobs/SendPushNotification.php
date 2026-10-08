<?php

namespace Abigah\SendIt\Jobs;

use Abigah\SendIt\Push\ApnsClient;
use Abigah\SendIt\Push\PushDevice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Fans a push notification out to every registered device, in chunks, and
 * forgets devices APNs says are gone.
 */
class SendPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $payload,
        public string $label = '',
    ) {}

    /**
     * @return array{sent: int, failed: int, removed: int}
     */
    public function handle(ApnsClient $client): array
    {
        $totals = ['sent' => 0, 'failed' => 0, 'removed' => 0];

        foreach (['production', 'sandbox'] as $environment) {
            PushDevice::query()
                ->where('environment', $environment)
                ->select(['id', 'token'])
                ->chunkById(500, function ($devices) use ($client, $environment, &$totals) {
                    $results = $client->send($devices->pluck('token')->all(), $this->payload, $environment);

                    $dead = [];

                    foreach ($results as $token => $result) {
                        if ($result['status'] === 200) {
                            $totals['sent']++;

                            continue;
                        }

                        $totals['failed']++;

                        if ($result['status'] === 410 || in_array($result['reason'], ApnsClient::DEAD_TOKEN_REASONS, true)) {
                            $dead[] = $token;
                        }
                    }

                    if ($dead !== []) {
                        $totals['removed'] += PushDevice::whereIn('token', $dead)->delete();
                    }
                });
        }

        Log::info("Send It push \"{$this->label}\": {$totals['sent']} delivered, {$totals['failed']} failed, {$totals['removed']} stale devices removed.");

        return $totals;
    }
}
