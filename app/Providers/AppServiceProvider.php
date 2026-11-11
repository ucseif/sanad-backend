<?php

namespace App\Providers;

use App\Application\Auth\OtpProvider;
use App\Infrastructure\Auth\WexoraOtpProvider;
use App\Models\CaregiverLink;
use App\Models\User;
use App\Policies\CaregiverLinkPolicy;
use App\Policies\PatientDataPolicy;
use App\Support\AuthApiResponse;
use App\Support\UserInput;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
        Gate::policy(CaregiverLink::class, CaregiverLinkPolicy::class);
        Gate::define('view-linked-patient', fn (User $actor, User $patient) => app(PatientDataPolicy::class)->view($actor, $patient));
        $limited = fn (Request $request, array $headers) => AuthApiResponse::error(
            'too-many-requests', 429, 'Too many requests. Please try again later.',
        )->withHeaders($headers);

        RateLimiter::for('auth-availability', fn (Request $request) => Limit::perMinute(30)->by('ip|'.$request->ip())->response($limited));
        foreach (['read' => [60, 120], 'write' => [20, 60]] as $operation => [$userLimit, $ipLimit]) {
            RateLimiter::for('medication-'.$operation, fn (Request $request) => [
                Limit::perMinute($userLimit)->by('medication-'.$operation.'-user|'.$request->user()->id)->response($limited),
                Limit::perMinute($ipLimit)->by('medication-'.$operation.'-ip|'.$request->ip())->response($limited),
            ]);
        }
        RateLimiter::for('caregiver-lookup', fn (Request $request) => [
            Limit::perMinute(10)->by('caregiver-lookup-user|'.$request->user()->id)->response($limited),
            Limit::perMinute(30)->by('caregiver-lookup-ip|'.$request->ip())->response($limited),
        ]);
        RateLimiter::for('caregiver-write', fn (Request $request) => [
            Limit::perMinute(20)->by('caregiver-write-user|'.$request->user()->id)->response($limited),
            Limit::perMinute(60)->by('caregiver-write-ip|'.$request->ip())->response($limited),
        ]);
        RateLimiter::for('caregiver-read', fn (Request $request) => Limit::perMinute(60)->by('caregiver-read-user|'.$request->user()->id)->response($limited));
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
