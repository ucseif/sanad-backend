<?php

namespace App\Http\Requests;

use App\Support\UserInput;

class ForgotPasswordRequest extends EmailInputRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        if (is_string($this->input('phone_number'))) {
            $this->merge(['phone_number' => UserInput::trim($this->input('phone_number'))]);
        }
    }

    public function rules(): array
    {
        return [...parent::rules(), 'phone_number' => ['bail', 'required', 'string', 'max:16', 'regex:/\A\+[0-9]{7,15}\z/'],
            'language' => ['sometimes', 'in:ar,en']];
    }
}
