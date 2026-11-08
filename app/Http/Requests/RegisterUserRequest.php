<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Rules\SignupPassword;
use App\Rules\UniqueUserIdentity;
use App\Support\UserInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Registration preparation only: no account creation, OTP assertion or token issuance. */
class RegisterUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['username', 'first_name', 'last_name', 'email', 'phone_number', 'country', 'region', 'address'] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = UserInput::trim($this->input($field));
            }
        }
        if (isset($normalized['email'])) {
            $normalized['email'] = mb_strtolower($normalized['email'], 'UTF-8');
        }
        $this->merge($normalized);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'username' => ['bail', 'required', 'string', 'min:4', 'max:15', 'regex:/\A[A-Za-z0-9._]+\z/', new UniqueUserIdentity('username')],
            'email' => ['bail', 'required', 'string', 'max:254', 'regex:/\A[^@\s]+@[^@\s]+\.[^@\s]+\z/u', new UniqueUserIdentity('email')],
            'password' => ['bail', 'required', 'string', new SignupPassword, 'confirmed'],
            'password_confirmation' => ['required', 'string', 'same:password'],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'phone_number' => ['required', 'string', 'regex:/\A\+[0-9]{7,15}\z/'],
            'country' => ['required', 'string', 'max:80'],
            'region' => ['required', 'string', 'max:80'],
            'address' => ['required', 'string', 'max:250'],
            'role' => ['required', Rule::enum(UserRole::class)],
        ];
    }
}
