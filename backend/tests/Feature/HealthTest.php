<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_returns_ok_when_database_is_reachable(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertExactJson(['status' => 'ok', 'database' => 'ok']);
    }

    public function test_returns_503_when_database_is_unreachable(): void
    {
        DB::shouldReceive('select')->andThrow(new RuntimeException('connection refused'));

        $response = $this->getJson('/api/health');

        $response->assertStatus(503)
            ->assertExactJson(['status' => 'error', 'database' => 'unreachable']);
    }
}
