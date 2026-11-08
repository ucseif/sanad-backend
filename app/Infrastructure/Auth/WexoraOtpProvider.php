<?php

namespace App\Infrastructure\Auth;

use App\Application\Auth\AuthFlowException;
use App\Application\Auth\OtpProvider;
use App\Application\Auth\ProviderChallenge;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

final class WexoraOtpProvider implements OtpProvider
{
    private const string BASE = 'https://wexora.pro/api/v1';

    private function request(): PendingRequest
    {
        if (! is_string(config('services.wexora.api_key')) || config('services.wexora.api_key') === ''
            || ! is_string(config('services.wexora.session_id')) || config('services.wexora.session_id') === '') {
            throw new AuthFlowException('otp-service-unavailable', 503);
        }

        return Http::withToken(config('services.wexora.api_key'))->acceptJson()->asJson()
            ->connectTimeout(5)->timeout(15)->withoutRedirecting();
    }

    public function send(string $phone, string $language, string $reference): ProviderChallenge
    {
        try {
            $response = $this->request()->withHeaders(['Idempotency-Key' => $reference])->post(self::BASE.'/otp', [
                'session_id' => config('services.wexora.session_id'), 'to' => $phone,
                // Only this provider purpose is evidenced in the existing integration.
                // Actual signup/login purpose is independently enforced by Laravel.
                'purpose' => 'sanad_signup', 'reference' => $reference, 'language' => $language,
            ]);
            if ($response->status() === 429) {
                throw new AuthFlowException('too-many-requests', 429);
            }
            $data = $response->json();
            if ($response->status() !== 202 || ! is_array($data) || ! is_string($data['id'] ?? null)
                || ! preg_match('/\A[A-Za-z0-9_-]{1,200}\z/', $data['id']) || ! is_string($data['expires_at'] ?? null)) {
                throw new AuthFlowException('otp-service-unavailable', 503);
            }
            $expiry = CarbonImmutable::parse($data['expires_at'], 'UTC');
            if (! $expiry->isFuture()) {
                throw new AuthFlowException('otp-service-unavailable', 503);
            }

            return new ProviderChallenge($data['id'], $expiry);
        } catch (AuthFlowException $error) {
            throw $error;
        } catch (Throwable) {
            throw new AuthFlowException('otp-service-unavailable', 503);
        }
    }

    public function verify(string $providerId, #[\SensitiveParameter] string $code): bool
    {
        try {
            $response = $this->request()->post(self::BASE.'/otp/'.rawurlencode($providerId).'/verify', ['code' => $code]);
            if ($response->status() === 429) {
                throw new AuthFlowException('too-many-requests', 429);
            }
            if (in_array($response->status(), [404, 410], true)) {
                throw new AuthFlowException('otp-expired', 410);
            }
            $verified = $response->json('verified');
            if ($response->status() === 422 && $verified === false) {
                return false;
            }
            if ($response->status() !== 200 || ! is_bool($verified)) {
                throw new AuthFlowException('otp-service-unavailable', 503);
            }

            return $verified;
        } catch (AuthFlowException $error) {
            throw $error;
        } catch (Throwable) {
            throw new AuthFlowException('otp-service-unavailable', 503);
        }
    }
}
