<?php

namespace App\Application\Medication;

use App\Support\AuthApiResponse;
use Illuminate\Http\Request;

final class MedicationFailure extends \RuntimeException
{
    public function __construct(public readonly string $machineCode, public readonly int $status)
    {
        parent::__construct($machineCode);
    }

    public function report(): bool
    {
        return true;
    }

    public function render(Request $request)
    {
        return AuthApiResponse::error($this->machineCode, $this->status, $this->machineCode);
    }
}
