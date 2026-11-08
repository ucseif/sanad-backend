<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Matches Flutter PasswordValidation, including its explicit ASCII character sets. */
final class SignupPassword implements ValidationRule
{
    private const string SPECIAL_CHARACTERS = '!@#$%^&*()_+-=[]{};:"\\|,.<>/?`~';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        // Dart String.length counts UTF-16 code units, not Unicode code points.
        $longEnough = strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')) >= 18;
        if (! $longEnough || ! preg_match('/[A-Z]/', $value) || ! preg_match('/[a-z]/', $value)
            || ! preg_match('/[0-9]/', $value)
            || ! preg_match('/['.preg_quote(self::SPECIAL_CHARACTERS, '/').']/', $value)) {
            $fail('The :attribute must contain at least 9 characters, an uppercase letter, a lowercase letter, a number, and an allowed special character.');
        }
    }
}
