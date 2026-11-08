<?php

namespace App\Application\Auth;

use App\Enums\UserRole;
use App\Http\Requests\RegisterUserRequest;
use App\Http\Resources\UserResource;
use App\Models\AuthAttempt;
use App\Models\User;
use App\Rules\UniqueUserIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

final class OtpAuthentication
{
    public function __construct(private OtpProvider $provider, private VerifyLoginCredentials $credentials, private OtpDelivery $delivery) {}

    public function signup(#[\SensitiveParameter] array $data): array
    {
        $passwordHash = $this->hashPassword($data['password']);
        unset($data['password'], $data['password_confirmation']);
        $data['password_hash'] = $passwordHash;

        return $this->create('signup', $data, null);
    }

    public function login(#[\SensitiveParameter] array $data): array
    {
        try {
            $user = $this->credentials->find($data['email'], $data['password']);
        } catch (QueryException) {
            throw new AuthFlowException('otp-service-unavailable', 503);
        }
        if (! $user) {
            throw new AuthFlowException('invalid-credentials', 401);
        }
        if (! $this->validUser($user)) {
            throw new AuthFlowException('profile-incomplete', 409);
        }

        return $this->create('login', ['phone_number' => $user->phone_number, 'language' => $data['language'] ?? 'en'], $user);
    }

    private function create(string $purpose, #[\SensitiveParameter] array $payload, ?User $user): array
    {
        $secret = bin2hex(random_bytes(32));
        $attempt = new AuthAttempt;
        try {
            $attempt->forceFill(['id' => (string) Str::uuid(), 'secret_hash' => hash('sha256', $secret), 'purpose' => $purpose,
                'user_id' => $user?->id, 'phone_hash' => $this->phoneHash($payload['phone_number']),
                'binding_hash' => $user ? $this->binding($user) : null, 'payload' => $payload,
                'expires_at' => now()->addSeconds(config('auth_otp.attempt_seconds')), 'status' => 'pending'])->save();
        } catch (QueryException) {
            throw new AuthFlowException('otp-service-unavailable', 503);
        }

        return ['attempt_id' => $attempt->id, 'attempt_token' => $secret, 'purpose' => $purpose,
            'otp_started' => false, 'authenticated' => false, 'expires_at' => $attempt->expires_at->toISOString()];
    }

    public function send(#[\SensitiveParameter] array $input): array
    {
        try {
            $result = DB::transaction(function () use ($input) {
                $attempt = $this->lock($input);
                if (! in_array($attempt->purpose, ['signup', 'login'], true)) {
                    throw new AuthFlowException('invalid-challenge', 404);
                }
                $this->pending($attempt);
                $this->phoneLock($attempt);
                $latestSend = AuthAttempt::where('phone_hash', $attempt->phone_hash)->max('last_sent_at');
                if ($attempt->send_count >= config('auth_otp.sends_per_attempt')
                    || ($attempt->last_sent_at && $attempt->last_sent_at->addSeconds(config('auth_otp.cooldown_seconds'))->isFuture())
                    || ($latestSend && CarbonImmutable::parse($latestSend)->addSeconds(config('auth_otp.cooldown_seconds'))->isFuture())
                    || AuthAttempt::where('phone_hash', $attempt->phone_hash)->where('last_sent_at', '>', now()->subMinutes(config('auth_otp.phone_window_minutes')))->sum('send_count') >= config('auth_otp.sends_per_phone')) {
                    throw new AuthFlowException('too-many-requests', 429);
                }
                if ($attempt->purpose === 'login') {
                    $this->boundUser($attempt);
                }
                $oldProviderHash = $attempt->provider_hash;
                $attempt->forceFill(['send_count' => $attempt->send_count + 1, 'last_sent_at' => now(),
                    'provider_id' => null, 'provider_hash' => null, 'challenge_expires_at' => null])->save();
                $failure = $this->delivery->send($attempt, $oldProviderHash);
                if ($failure) {
                    return $failure;
                }

                return ['attempt_id' => $attempt->id, 'otp_started' => true, 'authenticated' => false,
                    'expires_at' => $attempt->challenge_expires_at->toISOString(),
                    'resend_at' => $attempt->last_sent_at->addSeconds(config('auth_otp.cooldown_seconds'))->toISOString()];
            });
        } catch (QueryException) {
            throw new AuthFlowException('otp-service-unavailable', 503);
        }
        if ($result instanceof AuthFlowException) {
            throw $result;
        }

        return $result;
    }

    public function verify(#[\SensitiveParameter] array $input): array
    {
        try {
            $result = DB::transaction(function () use ($input) {
                $attempt = $this->lock($input, $input['purpose']);
                $this->pending($attempt);
                if (! $attempt->provider_id || ! $attempt->challenge_expires_at) {
                    throw new AuthFlowException('challenge-used', 409);
                }
                if (! $attempt->challenge_expires_at->isFuture()) {
                    throw new AuthFlowException('otp-expired', 410);
                }
                $this->phoneLock($attempt);
                if ($attempt->verify_count >= config('auth_otp.verifications_per_attempt')
                    || AuthAttempt::where('phone_hash', $attempt->phone_hash)->where('last_verify_at', '>', now()->subMinutes(config('auth_otp.phone_window_minutes')))->sum('verify_count') >= config('auth_otp.verifications_per_phone')) {
                    throw new AuthFlowException('too-many-requests', 429);
                }
                $user = $attempt->purpose === 'login' ? $this->boundUser($attempt) : null;
                $attempt->verify_count++;
                $attempt->last_verify_at = now();
                if ($attempt->verify_count >= config('auth_otp.verifications_per_attempt')) {
                    $attempt->status = 'locked';
                }
                $attempt->save();
                try {
                    $verified = $this->provider->verify($attempt->provider_id, $input['code']);
                } catch (AuthFlowException $error) {
                    return $error;
                }
                if (! $verified) {
                    return new AuthFlowException('invalid-code', 422);
                }
                // Recheck wall clock after a potentially slow provider response.
                if (! $attempt->expires_at->isFuture() || ! $attempt->challenge_expires_at->isFuture()) {
                    return new AuthFlowException('otp-expired', 410);
                }
                $attempt->verified_at = now();
                if ($user) {
                    $this->markPhoneVerified($user, $attempt);
                    $attempt->status = 'completed';
                    $attempt->completed_at = now();
                    $attempt->payload = null;
                    $attempt->save();

                    return $this->token($user);
                }
                $proof = bin2hex(random_bytes(32));
                $attempt->forceFill(['status' => 'verified', 'proof_hash' => hash('sha256', $proof),
                    'proof_expires_at' => now()->addSeconds(config('auth_otp.proof_seconds'))->min($attempt->expires_at)])->save();

                return ['verification_proof' => $proof, 'expires_at' => $attempt->proof_expires_at->toISOString(), 'authenticated' => false];
            });
        } catch (QueryException) {
            throw new AuthFlowException('otp-service-unavailable', 503);
        }
        if ($result instanceof AuthFlowException) {
            throw $result;
        }

        return $result;
    }

    public function finalize(#[\SensitiveParameter] array $input): array
    {
        try {
            return DB::transaction(function () use ($input) {
                $attempt = $this->lock($input, 'signup');
                if ($attempt->status !== 'verified') {
                    throw new AuthFlowException('challenge-used', 409);
                }
                if (! $attempt->proof_expires_at?->isFuture()) {
                    throw new AuthFlowException('otp-expired', 410);
                }
                if (! hash_equals($attempt->proof_hash, hash('sha256', $input['verification_proof']))) {
                    throw new AuthFlowException('invalid-challenge', 404);
                }
                $payload = $attempt->payload;
                $rules = (new RegisterUserRequest)->rules();
                unset($rules['password'], $rules['password_confirmation']);
                $profile = Validator::make($payload, $rules)->validate();
                $user = new User($profile);
                $user->role = UserRole::from($profile['role']);
                $user->password = $payload['password_hash'];
                $this->markPhoneVerified($user, $attempt);
                $attempt->forceFill(['status' => 'completed', 'completed_at' => now(), 'proof_hash' => null,
                    'payload' => null])->save();

                return $this->token($user);
            });
        } catch (UniqueConstraintViolationException $error) {
            $detail = $error->errorInfo[2] ?? '';
            if (str_contains($detail, 'users_username_')) {
                throw new AuthFlowException('username-taken', 422, 'username');
            }
            if (str_contains($detail, 'users_email_')) {
                throw new AuthFlowException('email-already-in-use', 422, 'email');
            }
            throw new AuthFlowException('otp-service-unavailable', 503);
        } catch (QueryException) {
            throw new AuthFlowException('otp-service-unavailable', 503);
        }
    }

    public function lock(#[\SensitiveParameter] array $input, ?string $purpose = null): AuthAttempt
    {
        $attempt = AuthAttempt::whereKey($input['attempt_id'])->lockForUpdate()->first();
        if (! $attempt || ! hash_equals($attempt->secret_hash, hash('sha256', $input['attempt_token']))
            || ($purpose !== null && $attempt->purpose !== $purpose)) {
            throw new AuthFlowException('invalid-challenge', 404);
        }
        if (! $attempt->expires_at->isFuture()) {
            throw new AuthFlowException('otp-expired', 410);
        }

        return $attempt;
    }

    private function pending(AuthAttempt $attempt): void
    {
        if ($attempt->status === 'locked') {
            throw new AuthFlowException('too-many-requests', 429);
        }
        if ($attempt->status !== 'pending') {
            throw new AuthFlowException('challenge-used', 409);
        }
    }

    public function phoneLock(AuthAttempt $attempt): void
    {
        $key = (int) hexdec(substr($attempt->phone_hash, 0, 15));
        DB::select('SELECT pg_advisory_xact_lock(?)', [$key]);
    }

    private function boundUser(AuthAttempt $attempt): User
    {
        $user = User::whereKey($attempt->user_id)->lockForUpdate()->first();
        if (! $user || ! $this->validUser($user) || ! hash_equals($attempt->binding_hash, $this->binding($user))) {
            throw new AuthFlowException('account-changed', 409);
        }

        return $user;
    }

    public function binding(User $user): string
    {
        return hash_hmac('sha256', $user->id.'|'.$user->getAuthPassword().'|'.$user->phone_number, config('app.key'));
    }

    public function phoneHash(string $phone): string
    {
        return hash_hmac('sha256', $phone, config('app.key'));
    }

    private function validUser(User $user): bool
    {
        $rules = (new RegisterUserRequest)->rules();
        unset($rules['password'], $rules['password_confirmation']);
        foreach (['email', 'username'] as $identity) {
            $rules[$identity] = array_values(array_filter($rules[$identity], fn ($rule) => ! $rule instanceof UniqueUserIdentity));
        }

        return Validator::make([...$user->getAttributes(), 'role' => $user->role?->value], $rules)->passes();
    }

    private function token(User $user): array
    {
        $expires = now()->addDays(config('auth_otp.token_days'));
        $token = $user->createToken('sanad-mobile', ['*'], $expires)->plainTextToken;

        return ['user' => (new UserResource($user))->resolve(request()), 'token' => $token,
            'token_type' => 'Bearer', 'expires_at' => $expires->toISOString(), 'authenticated' => true];
    }

    public function hashPassword(#[\SensitiveParameter] string $password): string
    {
        if (Hash::getDefaultDriver() === 'bcrypt' && strlen($password) > 72) {
            throw new AuthFlowException('weak-password', 422);
        }
        try {
            return Hash::make($password);
        } catch (Throwable) {
            throw new AuthFlowException('weak-password', 422);
        }
    }

    private function markPhoneVerified(User $user, AuthAttempt $attempt): void
    {
        $user->phone_verified_at = $attempt->verified_at;
        $user->phone_verified_hash = $this->phoneHash($user->phone_number);
        $user->save();
    }
}
