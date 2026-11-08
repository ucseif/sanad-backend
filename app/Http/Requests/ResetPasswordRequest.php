<?php

namespace App\Http\Requests;

use App\Rules\SignupPassword;

class ResetPasswordRequest extends OtpAttemptRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'reset_proof' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'password' => ['bail', 'required', 'string', new SignupPassword, 'confirmed'],
            'password_confirmation' => ['required', 'string', 'same:password']];
    }
}
