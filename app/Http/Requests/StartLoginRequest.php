<?php

namespace App\Http\Requests;

class StartLoginRequest extends LoginPreparationRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'language' => ['sometimes', 'string', 'in:ar,en']];
    }
}
