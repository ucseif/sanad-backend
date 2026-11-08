<?php

namespace App\Application\Auth;

use Carbon\CarbonImmutable;

final readonly class ProviderChallenge
{
    public function __construct(public string $id, public CarbonImmutable $expiresAt) {}
}
