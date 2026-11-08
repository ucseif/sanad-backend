<?php

namespace App\Http\Controllers;

use App\Application\Auth\PasswordRecovery;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\OtpAttemptRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Requests\VerifyRecoveryOtpRequest;
use App\Support\AuthApiResponse;

class PasswordRecoveryController extends Controller
{
    public function forgot(ForgotPasswordRequest $request, PasswordRecovery $flow)
    {
        return AuthApiResponse::success('password-recovery-started', $flow->forgot($request->validated()))->setStatusCode(202);
    }

    public function send(OtpAttemptRequest $request, PasswordRecovery $flow)
    {
        return AuthApiResponse::success('recovery-request-accepted', $flow->send($request->validated()))->setStatusCode(202);
    }

    public function verify(VerifyRecoveryOtpRequest $request, PasswordRecovery $flow)
    {
        return AuthApiResponse::success('password-recovery-verified', $flow->verify($request->validated()));
    }

    public function reset(ResetPasswordRequest $request, PasswordRecovery $flow)
    {
        return AuthApiResponse::success('password-reset', $flow->reset($request->validated()));
    }
}
