<?php

namespace App\Http\Requests;

class VerifyRecoveryOtpRequest extends OtpAttemptRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/']];
    }
}
