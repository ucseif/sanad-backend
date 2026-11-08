<?php

namespace App\Jobs;

use App\Application\Auth\AuthFlowException;
use App\Application\Auth\PasswordRecovery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;

final class DeliverRecoveryOtp implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $attemptId, public readonly int $generation) {}

    public function handle(PasswordRecovery $recovery): void
    {
        try {
            $recovery->deliver($this->attemptId, $this->generation);
        } catch (QueryException) {
            throw new AuthFlowException('otp-service-unavailable', 503);
        }
    }
}
