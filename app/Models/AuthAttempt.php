<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuthAttempt extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $hidden = ['secret_hash', 'payload', 'provider_id', 'provider_hash', 'binding_hash', 'phone_hash', 'proof_hash'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'provider_id' => 'encrypted', 'last_sent_at' => 'immutable_datetime', 'last_verify_at' => 'immutable_datetime',
            'challenge_expires_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime',
            'proof_expires_at' => 'immutable_datetime', 'verified_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }
}
