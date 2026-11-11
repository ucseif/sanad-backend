<?php

namespace App\Application\Auth;

use RuntimeException;

class AuthFlowException extends RuntimeException
{
    public function __construct(public readonly string $machineCode, public readonly int $status, public readonly ?string $field = null)
    {
        parent::__construct(match ($machineCode) {
            'invalid-credentials' => 'The email or password is incorrect.',
            'recovery-not-eligible' => 'Unable to continue with these details. Check them and try again.',
            'invalid-code' => 'The verification code is invalid.',
            'invalid-challenge' => 'The authentication attempt is invalid.',
            'otp-expired' => 'The authentication attempt or verification proof has expired.',
            'too-many-requests' => 'Too many attempts. Please try again later.',
            'challenge-used' => 'This authentication step is unavailable or already completed.',
            'profile-incomplete' => 'The account profile requires remediation before authentication.',
            'account-changed' => 'The account changed. Start a new login attempt.',
            'weak-password' => 'The password cannot safely be processed by the configured password hasher.',
            'email-already-in-use' => 'This email is already in use.',
            'username-taken' => 'This username is already in use.',
            default => 'The OTP service is temporarily unavailable.',
        });
    }
}
