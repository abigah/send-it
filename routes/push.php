<?php

use Abigah\SendIt\Push\DeviceController;
use Illuminate\Support\Facades\Route;

$config = config('send-it.channels.apns.devices', []);

Route::middleware($config['middleware'] ?? ['api', 'throttle:30,1'])
    ->prefix(trim($config['route'], '/'))
    ->group(function () {
        Route::post('/', [DeviceController::class, 'store'])->name('send-it.push.devices.store');
        Route::delete('{token}', [DeviceController::class, 'destroy'])->name('send-it.push.devices.destroy');
    });
