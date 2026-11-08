<?php

namespace App\Application\Auth;

use App\Jobs\DeliverRecoveryOtp;
use App\Models\AuthAttempt;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;

final class PasswordRecovery
{
    public function __construct(private OtpAuthentication $auth, private OtpDelivery $delivery, private OtpProvider $provider) {}

    public function forgot(#[\SensitiveParameter] array $input): array
    {
        return $this->database(fn () => $this->prepare($input));
    }

    public function send(#[\SensitiveParameter] array $input): array
    {
        return $this->database(fn () => $this->reserve($input));
    }

    public function verify(#[\SensitiveParameter] array $input): array
    {
        return $this->database(fn () => $this->check($input));
    }

    public function reset(#[\SensitiveParameter] array $input): array
    {
        return $this->database(fn () => $this->consume($input));
    }

    private function database(callable $operation): array
    {
        try {
            return $operation();
        } catch (QueryException) {
            throw new AuthFlowException('otp-service-unavailable', 503);
        }
    }

    private function prepare(#[\SensitiveParameter] array $input): array
    {
        return (new Timebox)->call(function ($timebox) use ($input) {
            $user = User::whereRaw('lower(trim(email)) = lower(trim(?))', [$input['email']])->first();
            if (! $user || ! $this->eligible($user)) {
                $user = null;
            }
            $secret = bin2hex(random_bytes(32));
            $attempt = new AuthAttempt;
            $attempt->forceFill(['id' => (string) Str::uuid(), 'secret_hash' => hash('sha256', $secret),
                'purpose' => 'password_reset', 'user_id' => $user?->id,
                'phone_hash' => $user ? $this->auth->phoneHash($user->phone_number) : $this->auth->phoneHash('recovery|'.$input['email']),
                'binding_hash' => $user ? $this->auth->binding($user) : null,
                'payload' => ['phone_number' => $user?->phone_number, 'language' => $input['language'] ?? 'en'],
                'status' => 'pending', 'expires_at' => now()->addSeconds(config('auth_otp.attempt_seconds'))])->save();

            return ['attempt_id' => $attempt->id, 'attempt_token' => $secret, 'purpose' => 'password_reset',
                'expires_at' => $attempt->expires_at->toISOString(), 'authenticated' => false];
        }, 200000);
    }

    private function reserve(#[\SensitiveParameter] array $input): array
    {
        if (config('queue.connections.database.driver') !== 'database' ||
            (config('queue.connections.database.connection') ?? config('database.default')) !== config('database.default')) {
            throw new AuthFlowException('otp-service-unavailable', 503);
        }

        return DB::transaction(function () use ($input) {
            $attempt = $this->auth->lock($input, 'password_reset');
            $this->pending($attempt);
            if ($attempt->send_count >= config('auth_otp.sends_per_attempt') ||
                $attempt->last_sent_at?->addSeconds(config('auth_otp.cooldown_seconds'))->isFuture()) {
                throw new AuthFlowException('too-many-requests', 429);
            }
            // Persist a reservation and its job in the same database transaction.
            $payload = $attempt->payload;
            $payload['previous_provider_hash'] = $attempt->provider_hash ?? ($payload['previous_provider_hash'] ?? null);
            $attempt->forceFill(['payload' => $payload, 'send_count' => $attempt->send_count + 1,
                'last_sent_at' => now(), 'provider_id' => null, 'provider_hash' => null, 'challenge_expires_at' => null])->save();
            DeliverRecoveryOtp::dispatch($attempt->id, $attempt->send_count)->onConnection('database')->onQueue('auth-recovery')->beforeCommit();

            return ['attempt_id' => $attempt->id, 'authenticated' => false,
                'resend_at' => $attempt->last_sent_at->addSeconds(config('auth_otp.cooldown_seconds'))->toISOString()];
        });
    }

    public function deliver(string $id, int $generation): void
    {
        DB::transaction(function () use ($id, $generation): void {
            $attempt = AuthAttempt::whereKey($id)->lockForUpdate()->first();
            if (! $attempt || $attempt->purpose !== 'password_reset' || $attempt->status !== 'pending' ||
                ! $attempt->expires_at->isFuture() || $attempt->send_count !== $generation || $attempt->recovery_delivery_generation >= $generation) {
                return;
            }
            $attempt->forceFill(['recovery_delivery_generation' => $generation])->save();
            $this->auth->phoneLock($attempt);
            $window = AuthAttempt::where('phone_hash', $attempt->phone_hash)->where('last_sent_at', '>', now()->subMinutes(config('auth_otp.phone_window_minutes')));
            if ((clone $window)->sum('send_count') > config('auth_otp.sends_per_phone') ||
                (clone $window)->where('id', '!=', $attempt->id)->where('last_sent_at', '>', now()->subSeconds(config('auth_otp.cooldown_seconds')))->exists()) {
                return;
            }
            if (! $this->boundUser($attempt)) {
                return;
            }
            $this->delivery->send($attempt, $attempt->payload['previous_provider_hash'] ?? null);
        });
    }

    private function check(#[\SensitiveParameter] array $input): array
    {
        $result = (new Timebox)->call(function ($timebox) use ($input) {
            return DB::transaction(function () use ($input) {
                $attempt = $this->auth->lock($input, 'password_reset');
                $this->pending($attempt);
                $this->auth->phoneLock($attempt);
                $phoneLimited = AuthAttempt::where('phone_hash', $attempt->phone_hash)->where('last_verify_at', '>', now()->subMinutes(config('auth_otp.phone_window_minutes')))->sum('verify_count') >= config('auth_otp.verifications_per_phone');
                $user = $this->boundUser($attempt);
                $real = $user && ! $phoneLimited && $attempt->provider_id && $attempt->challenge_expires_at?->isFuture();
                $attempt->verify_count++;
                $attempt->last_verify_at = now();
                if ($attempt->verify_count >= config('auth_otp.verifications_per_attempt')) {
                    $attempt->status = 'locked';
                }
                $attempt->save();
                try {
                    // An unresolvable provider lookup keeps decoy requests on the same I/O path.
                    // A positive answer to this lookup can NEVER authorize a reset.
                    $verified = $this->provider->verify($real ? $attempt->provider_id : bin2hex(random_bytes(16)), $input['code']);
                } catch (AuthFlowException) {
                    $verified = false;
                }
                if (! $real || ! $verified || ! $attempt->expires_at->isFuture() || ! $attempt->challenge_expires_at->isFuture()) {
                    return new AuthFlowException('invalid-code', 422);
                }
                $proof = bin2hex(random_bytes(32));
                $attempt->forceFill(['status' => 'verified', 'verified_at' => now(), 'proof_hash' => hash('sha256', $proof),
                    'proof_expires_at' => now()->addSeconds(config('auth_otp.proof_seconds'))->min($attempt->expires_at)])->save();

                return ['reset_proof' => $proof, 'expires_at' => $attempt->proof_expires_at->toISOString(), 'authenticated' => false];
            });
        }, 200000);
        if ($result instanceof AuthFlowException) {
            throw $result;
        }

        return $result;
    }

    private function consume(#[\SensitiveParameter] array $input): array
    {
        return DB::transaction(function () use ($input) {
            $attempt = $this->auth->lock($input, 'password_reset');
            if ($attempt->status !== 'verified') {
                throw new AuthFlowException('challenge-used', 409);
            }
            if (! $attempt->proof_expires_at?->isFuture()) {
                throw new AuthFlowException('otp-expired', 410);
            }
            if (! $attempt->proof_hash || ! hash_equals($attempt->proof_hash, hash('sha256', $input['reset_proof']))) {
                throw new AuthFlowException('invalid-challenge', 404);
            }
            $user = $this->boundUser($attempt);
            if (! $user) {
                throw new AuthFlowException('account-changed', 409);
            }
            $user->password = $this->auth->hashPassword($input['password']);
            $user->save();
            $user->tokens()->delete();
            $attempt->forceFill(['status' => 'completed', 'completed_at' => now(), 'proof_hash' => null, 'payload' => null])->save();

            return ['authenticated' => false, 'login_required' => true];
        });
    }

    private function pending(AuthAttempt $attempt): void
    {
        if ($attempt->status === 'locked' || $attempt->verify_count >= config('auth_otp.verifications_per_attempt')) {
            throw new AuthFlowException('too-many-requests', 429);
        }
        if ($attempt->status !== 'pending') {
            throw new AuthFlowException('challenge-used', 409);
        }
    }

    private function boundUser(AuthAttempt $attempt): ?User
    {
        $user = User::whereKey($attempt->user_id)->lockForUpdate()->first();

        return $user && $this->eligible($user) && hash_equals($attempt->binding_hash ?? '', $this->auth->binding($user)) ? $user : null;
    }

    private function eligible(User $user): bool
    {
        if (! is_string($user->phone_number) || ! preg_match('/\A\+[0-9]{7,15}\z/', $user->phone_number)) {
            return false;
        }
        $hash = $this->auth->phoneHash($user->phone_number);

        return ($user->phone_verified_at && hash_equals($user->phone_verified_hash ?? '', $hash)) ||
            AuthAttempt::where('purpose', 'login')->where('user_id', $user->id)->where('phone_hash', $hash)->where('status', 'completed')->whereNotNull('verified_at')->exists();
    }
}
