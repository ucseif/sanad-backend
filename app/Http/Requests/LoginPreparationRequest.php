<?php

namespace App\Http\Requests;

class LoginPreparationRequest extends EmailInputRequest
{
    public function rules(): array
    {
        // Existing credentials need not satisfy the new-registration policy.
        return [...parent::rules(), 'password' => ['required', 'string']];
    }
}
