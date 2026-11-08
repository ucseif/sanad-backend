<?php

namespace App\Http\Requests;

class FinalizeSignupRequest extends OtpAttemptRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'verification_proof' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/']];
    }
}
