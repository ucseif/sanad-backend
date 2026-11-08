<?php

namespace App\Http\Requests;

class ForgotPasswordRequest extends EmailInputRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'language' => ['sometimes', 'in:ar,en']];
    }
}
