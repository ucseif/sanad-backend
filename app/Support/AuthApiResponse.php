<?php

namespace App\Support;

use App\Rules\SignupPassword;
use App\Rules\UniqueUserIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class AuthApiResponse
{
    public static function success(string $code, array $data): JsonResponse
    {
        return response()->json(['code' => $code, 'data' => $data], 200, ['Cache-Control' => 'no-store, private']);
    }

    public static function error(string $code, int $status, string $message): JsonResponse
    {
        return response()->json(['code' => $code, 'message' => $message], $status, ['Cache-Control' => 'no-store, private']);
    }

    public static function validation(ValidationException $exception): JsonResponse
    {
        $codes = [];
        foreach ($exception->validator->failed() as $field => $rules) {
            $code = match (true) {
                isset($rules['Required']) => 'required',
                isset($rules[UniqueUserIdentity::class]) => $field === 'email' ? 'email-already-in-use' : 'username-taken',
                isset($rules['Same']), isset($rules['Confirmed']) => 'password-mismatch',
                isset($rules['Min']) => 'too-short',
                isset($rules['Max']) => 'too-long',
                isset($rules[SignupPassword::class]) => 'weak-password',
                default => match ($field) {
                    'email' => 'invalid-email', 'username' => 'invalid-username',
                    'phone_number' => 'invalid-phone-number', 'role' => 'invalid-role',
                    default => 'invalid-data',
                },
            };
            $codes[$field] = [$code];
        }

        return response()->json(['code' => 'validation-failed', 'message' => 'The submitted data is invalid.',
            'errors' => $exception->errors(), 'error_codes' => $codes], 422, ['Cache-Control' => 'no-store, private']);
    }
}
