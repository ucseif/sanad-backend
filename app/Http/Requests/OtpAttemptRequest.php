<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OtpAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['attempt_id' => ['required', 'uuid'], 'attempt_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/']];
    }
}
