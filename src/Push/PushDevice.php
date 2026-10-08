<?php

namespace Abigah\SendIt\Push;

use Illuminate\Database\Eloquent\Model;

/**
 * An app install that can receive Apple push notifications.
 *
 * @property string $token
 * @property string $platform
 * @property string $environment
 */
class PushDevice extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    public function getTable()
    {
        return config('send-it.channels.apns.devices.table', 'send_it_push_devices');
    }
}
