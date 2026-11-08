<?php

namespace App\Http\Requests;

class StartSignupRequest extends RegisterUserRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'language' => ['sometimes', 'string', 'in:ar,en']];
    }
}
