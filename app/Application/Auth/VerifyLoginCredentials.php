<?php

namespace App\Application\Auth;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Timebox;
use RuntimeException;

final class VerifyLoginCredentials
{
    public function verify(string $email, #[\SensitiveParameter] string $password): bool
    {
        return $this->find($email, $password) !== null;
    }

    public function find(string $email, #[\SensitiveParameter] string $password): ?User
    {
        // Provider validation only: no guard login, auth event, rehash or save.
        // Apply the same minimum processing interval to missing/wrong/valid users.
        return (new Timebox)->call(function () use ($email, $password): ?User {
            $provider = Auth::createUserProvider('users');
            $user = $provider->retrieveByCredentials([
                'email' => fn (Builder $query) => $query->whereRaw('lower(trim(email)) = lower(trim(?))', [$email]),
            ]);

            try {
                return $user instanceof User && $provider->validateCredentials($user, ['password' => $password]) ? $user : null;
            } catch (RuntimeException) {
                // An unusable/unsupported stored hash is not an account-existence signal.
                return null;
            }
        }, 200_000);
    }
}
