<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class UniqueUserIdentity implements ValidationRule
{
    public function __construct(private readonly string $column)
    {
        if (! in_array($column, ['email', 'username'], true)) {
            throw new InvalidArgumentException('Only user email and username support this identity rule.');
        }
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        // Same expression as the PostgreSQL indexes; values stay parameterized.
        if (DB::table('users')->whereRaw("lower(trim({$this->column})) = lower(trim(?))", [$value])->exists()) {
            $fail('validation.unique')->translate();
        }
    }
}
