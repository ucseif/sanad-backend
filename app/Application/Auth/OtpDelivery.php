<?php

namespace App\Application\Auth;

use App\Models\AuthAttempt;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Caller must hold the attempt lock and reserve its send budget first. */
final class OtpDelivery
{
    public function __construct(private OtpProvider $provider) {}

    public function send(AuthAttempt $attempt, ?string $previousProviderHash): ?AuthFlowException
    {
        try {
            $payload = $attempt->payload;
            $challenge = $this->provider->send($payload['phone_number'], $payload['language'] ?? 'en', 'sanad-'.$attempt->id.'-'.$attempt->send_count);
            if (hash('sha256', $challenge->id) === $previousProviderHash) {
                throw new AuthFlowException('otp-service-unavailable', 503);
            }
            DB::transaction(function () use ($attempt, $challenge): void {
                $attempt->forceFill(['provider_id' => $challenge->id, 'provider_hash' => hash('sha256', $challenge->id),
                    'challenge_expires_at' => $challenge->expiresAt->min($attempt->expires_at)])->save();
            });

            return null;
        } catch (AuthFlowException $error) {
            return $error;
        } catch (UniqueConstraintViolationException) {
            return new AuthFlowException('otp-service-unavailable', 503);
        }
    }
}
