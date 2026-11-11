<?php

namespace App\Http\Requests;

use App\Support\UserInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CaregiverLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['username', 'caregiver_username'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => UserInput::trim($this->input($key))]);
            }
        }
    }

    public function rules(): array
    {
        $username = ['bail', 'required', 'string', 'min:4', 'max:15', 'regex:/\A[A-Za-z0-9._]+\z/'];

        return match ($this->route()->getName()) {
            'caregiver-links.lookup' => ['username' => $username],
            'caregiver-links.store' => ['caregiver_username' => $username],
            'caregiver-links.index' => ['status' => ['sometimes', Rule::in(['all', 'pending', 'active', 'declined', 'revoked', 'cancelled'])],
                'page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:50']],
            default => [],
        };
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $key) {
                $validator->errors()->add($key, 'This field is not accepted.');
            }
        }];
    }
}
