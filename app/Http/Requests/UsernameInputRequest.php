<?php

namespace App\Http\Requests;

use App\Support\UserInput;
use Illuminate\Foundation\Http\FormRequest;

class UsernameInputRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('username'))) {
            $this->merge(['username' => UserInput::trim($this->input('username'))]);
        }
    }

    public function rules(): array
    {
        return ['username' => ['bail', 'required', 'string', 'min:4', 'max:15', 'regex:/\A[A-Za-z0-9._]+\z/']];
    }
}
