<?php

use App\Models\AuthAttempt;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('auth:prune-attempts', function () {
    $count = AuthAttempt::where('expires_at', '<', now()->subHours(config('auth_otp.retention_hours')))->delete();
    $this->info('Expired authentication attempts removed: '.$count);
})->purpose('Remove expired authentication attempt state after its retention period');

Schedule::command('auth:prune-attempts')->daily();
