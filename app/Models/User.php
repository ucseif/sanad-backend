<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Support\UserInput;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'username', 'first_name', 'last_name', 'phone_number', 'country', 'region', 'address'])]
#[Hidden(['password', 'remember_token', 'tokens', 'phone_verified_at', 'phone_verified_hash'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            // Existing incomplete accounts retain their original Laravel name.
            if (is_string($user->first_name) && UserInput::trim($user->first_name) !== ''
                && is_string($user->last_name) && UserInput::trim($user->last_name) !== '') {
                $user->name = UserInput::trim($user->first_name).' '.UserInput::trim($user->last_name);
            }
        });
    }

    /** @return Attribute<string, string> */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => mb_strtolower(UserInput::trim($value), 'UTF-8'));
    }

    /** @return Attribute<string|null, string|null> */
    protected function username(): Attribute
    {
        return Attribute::make(set: fn (?string $value): ?string => $value === null ? null : UserInput::trim($value));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'phone_verified_at' => 'immutable_datetime',
        ];
    }
}
