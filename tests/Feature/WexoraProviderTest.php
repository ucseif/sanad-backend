<?php

namespace Tests\Feature;

use App\Application\Auth\AuthFlowException;
use App\Infrastructure\Auth\WexoraOtpProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WexoraProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.wexora.api_key' => 'test-key', 'services.wexora.session_id' => 'test-session']);
    }

    public function test_send_uses_only_the_inspected_wexora_contract_and_does_not_return_code_material(): void
    {
        $providerId = bin2hex(random_bytes(16));
        Http::fake(['https://wexora.pro/api/v1/otp' => Http::response([
            'id' => $providerId, 'expires_at' => now()->addMinutes(5)->format('Y-m-d H:i:s'),
        ], 202)]);
        $result = app(WexoraOtpProvider::class)->send('+201012345678', 'ar', 'server-reference');
        $this->assertTrue($result->expiresAt->isFuture());
        $this->assertTrue(hash_equals($providerId, $result->id));
        Http::assertSent(fn ($request) => $request->url() === 'https://wexora.pro/api/v1/otp'
            && $request->method() === 'POST' && $request->hasHeader('Authorization', 'Bearer test-key')
            && $request->hasHeader('Idempotency-Key', 'server-reference')
            && $request->data() === ['session_id' => 'test-session', 'to' => '+201012345678',
                'purpose' => 'sanad_signup', 'reference' => 'server-reference', 'language' => 'ar']);
    }

    public function test_verify_uses_the_provider_challenge_and_requires_an_authoritative_boolean(): void
    {
        $providerId = bin2hex(random_bytes(16));
        $code = (string) random_int(100000, 999999);
        Http::fake(['https://wexora.pro/api/v1/otp/*/verify' => Http::response(['verified' => true], 200)]);
        $this->assertTrue(app(WexoraOtpProvider::class)->verify($providerId, $code));
        Http::assertSent(fn ($request) => $request->url() === 'https://wexora.pro/api/v1/otp/'.$providerId.'/verify'
            && $request->method() === 'POST' && $request->data() === ['code' => $code]);
    }

    public function test_truthy_provider_strings_are_not_verification(): void
    {
        Http::fake(['*' => Http::response(['verified' => 'true'], 200)]);
        $this->expectException(AuthFlowException::class);
        app(WexoraOtpProvider::class)->verify(bin2hex(random_bytes(16)), (string) random_int(100000, 999999));
    }

    public function test_provider_verify_timeout_fails_closed_without_returning_provider_details(): void
    {
        Http::fake(fn () => throw new ConnectionException('Simulated timeout'));
        try {
            app(WexoraOtpProvider::class)->verify(bin2hex(random_bytes(16)), (string) random_int(100000, 999999));
            $this->fail('Timeout must fail closed.');
        } catch (AuthFlowException $error) {
            $this->assertSame('otp-service-unavailable', $error->machineCode);
            $this->assertSame(503, $error->status);
            $this->assertNull($error->getPrevious());
        }
    }

    public function test_missing_credentials_prevent_any_network_request(): void
    {
        config(['services.wexora.api_key' => '']);
        try {
            app(WexoraOtpProvider::class)->send('+201012345678', 'en', 'reference');
            $this->fail('Missing credentials must fail closed.');
        } catch (AuthFlowException $error) {
            $this->assertSame(503, $error->status);
            Http::assertNothingSent();
        }
    }
}
