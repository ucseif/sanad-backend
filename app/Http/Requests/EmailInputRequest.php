<?php

namespace App\Http\Requests;

use App\Support\UserInput;
use Illuminate\Foundation\Http\FormRequest;

class EmailInputRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(UserInput::trim($this->input('email')), 'UTF-8')]);
        }
    }

    public function rules(): array
    {
        return ['email' => ['bail', 'required', 'string', 'max:254', 'regex:/\A[^@\s]+@[^@\s]+\.[^@\s]+\z/u']];
    }
}
