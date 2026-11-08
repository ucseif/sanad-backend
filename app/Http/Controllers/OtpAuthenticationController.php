<?php

namespace App\Http\Controllers;

use App\Application\Auth\OtpAuthentication;
use App\Http\Requests\FinalizeSignupRequest;
use App\Http\Requests\OtpAttemptRequest;
use App\Http\Requests\StartLoginRequest;
use App\Http\Requests\StartSignupRequest;
use App\Http\Requests\VerifyOtpRequest;
use App\Support\AuthApiResponse;
use Illuminate\Http\Request;

class OtpAuthenticationController extends Controller
{
    public function signup(StartSignupRequest $request, OtpAuthentication $flow)
    {
        return AuthApiResponse::success('signup-started', $flow->signup($request->validated()))->setStatusCode(201);
    }

    public function login(StartLoginRequest $request, OtpAuthentication $flow)
    {
        return AuthApiResponse::success('login-started', $flow->login($request->validated()))->setStatusCode(201);
    }

    public function send(OtpAttemptRequest $request, OtpAuthentication $flow)
    {
        return AuthApiResponse::success('otp-sent', $flow->send($request->validated()))->setStatusCode(202);
    }

    public function verify(VerifyOtpRequest $request, OtpAuthentication $flow)
    {
        $result = $flow->verify($request->validated());

        return AuthApiResponse::success(isset($result['token']) ? 'authenticated' : 'signup-verified', $result);
    }

    public function finalize(FinalizeSignupRequest $request, OtpAuthentication $flow)
    {
        return AuthApiResponse::success('registered', $flow->finalize($request->validated()))->setStatusCode(201);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent()->header('Cache-Control', 'no-store, private');
    }
}
