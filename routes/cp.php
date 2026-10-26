<?php

use Abigah\SendIt\Http\Controllers\CP\OverviewController;
use Illuminate\Support\Facades\Route;

Route::prefix('send-it')->name('send-it.')->group(function () {
    Route::get('/', OverviewController::class)->name('index');
});
