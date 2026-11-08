<?php

namespace App\Application\Auth;

interface OtpProvider
{
    public function send(string $phone, string $language, string $reference): ProviderChallenge;

    public function verify(string $providerId, #[\SensitiveParameter] string $code): bool;
}
