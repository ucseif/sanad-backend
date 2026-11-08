<?php

namespace App\Http\Controllers;

use App\Application\Auth\VerifyLoginCredentials;
use App\Http\Requests\EmailInputRequest;
use App\Http\Requests\LoginPreparationRequest;
use App\Http\Requests\RegisterUserRequest;
use App\Http\Requests\UsernameInputRequest;
use App\Support\AuthApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AuthPreparationController extends Controller
{
    public function emailAvailability(EmailInputRequest $request): JsonResponse
    {
        $exists = DB::table('users')->whereRaw('lower(trim(email)) = lower(trim(?))', [$request->validated('email')])->exists();

        return AuthApiResponse::success('availability-checked', ['available' => ! $exists]);
    }

    public function usernameAvailability(UsernameInputRequest $request): JsonResponse
    {
        $exists = DB::table('users')->whereRaw('lower(trim(username)) = lower(trim(?))', [$request->validated('username')])->exists();

        return AuthApiResponse::success('availability-checked', ['available' => ! $exists]);
    }

    public function login(LoginPreparationRequest $request, VerifyLoginCredentials $credentials): JsonResponse
    {
        if (! $credentials->verify($request->validated('email'), $request->validated('password'))) {
            return AuthApiResponse::error('invalid-credentials', 401, 'The email or password is incorrect.');
        }

        return $this->prepared('login-prepared');
    }

    public function register(RegisterUserRequest $request): JsonResponse
    {
        // Validation is the entire operation. No proof is produced or accepted.
        return $this->prepared('registration-prepared');
    }

    private function prepared(string $code): JsonResponse
    {
        return AuthApiResponse::success($code, ['status' => 'prepared', 'otp_required' => true,
            'otp_started' => false, 'authenticated' => false, 'next_step' => 'server-verified-otp']);
    }
}
