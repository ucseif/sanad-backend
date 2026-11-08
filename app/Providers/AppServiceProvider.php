<?php

namespace App\Providers;

use App\Application\Auth\OtpProvider;
use App\Infrastructure\Auth\WexoraOtpProvider;
use App\Support\AuthApiResponse;
use App\Support\UserInput;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(OtpProvider::class, WexoraOtpProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $limited = fn (Request $request, array $headers) => AuthApiResponse::error(
            'too-many-requests', 429, 'Too many requests. Please try again later.',
        )->withHeaders($headers);

        RateLimiter::for('auth-availability', fn (Request $request) => Limit::perMinute(30)->by('ip|'.$request->ip())->response($limited));
        RateLimiter::for('auth-registration', fn (Request $request) => Limit::perMinute(5)->by('ip|'.$request->ip())->response($limited));
        RateLimiter::for('auth-otp-send', fn (Request $request) => Limit::perMinute(10)->by('ip|'.$request->ip())->response($limited));
        RateLimiter::for('auth-otp-verify', fn (Request $request) => Limit::perMinute(20)->by('ip|'.$request->ip())->response($limited));
        RateLimiter::for('auth-finalize', fn (Request $request) => Limit::perMinute(5)->by('ip|'.$request->ip())->response($limited));
        RateLimiter::for('auth-login', function (Request $request) use ($limited): array {
            $email = $request->input('email');
            $identity = is_string($email) ? mb_strtolower(UserInput::trim($email), 'UTF-8') : '';

            return [
                Limit::perMinute(20)->by('ip|'.$request->ip())->response($limited),
                Limit::perMinute(5)->by('identity|'.hash('sha256', $identity).'|'.$request->ip())->response($limited),
            ];
        });
        RateLimiter::for('auth-recovery', function (Request $request) use ($limited): array {
            $email = $request->input('email');
            $identity = is_string($email) ? mb_strtolower(UserInput::trim($email), 'UTF-8') : '';

            return [Limit::perMinute(20)->by('ip|'.$request->ip())->response($limited),
                Limit::perMinutes(15, 5)->by('identity|'.hash('sha256', $identity).'|'.$request->ip())->response($limited)];
        });
    }
}
