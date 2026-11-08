<?php

namespace App\Http\Requests;

class VerifyOtpRequest extends OtpAttemptRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'purpose' => ['required', 'in:signup,login'], 'code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/']];
    }
}
