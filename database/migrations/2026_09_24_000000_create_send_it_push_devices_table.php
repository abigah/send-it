<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('send-it.channels.apns.devices.table', 'send_it_push_devices'), function (Blueprint $table) {
            $table->id();
            $table->string('token', 200)->unique();
            $table->string('platform', 16)->default('ios');
            $table->string('environment', 16)->default('production')->index();
            $table->string('app_version', 32)->nullable();
            $table->string('locale', 35)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('send-it.channels.apns.devices.table', 'send_it_push_devices'));
    }
};
