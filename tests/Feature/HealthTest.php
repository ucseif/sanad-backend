<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_existing_api_health_contract_is_preserved(): void
    {
        $this->getJson('/api/health')->assertOk()->assertExactJson([
            'success' => true,
            'message' => 'SANAD API is running',
            'environment' => 'testing',
        ]);
    }
}
