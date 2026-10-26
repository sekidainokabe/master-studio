<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MasterApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    private function actingAsEditor(): static
    {
        return $this->actingAs(User::where('role', UserRole::Editor)->firstOrFail(), 'web');
    }

    public function test_index_lists_masters_with_record_count_and_last_update(): void
    {
        $response = $this->actingAsEditor()->getJson('/api/masters');

        $response->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.key', 'abilities')
            ->assertJsonPath('data.0.name', '攻撃パターン')
            ->assertJsonPath('data.0.record_count', 20)
            ->assertJsonPath('data.3.key', 'stage_enemies')
            ->assertJsonPath('data.3.record_count', 150);
        $this->assertNotNull($response->json('data.0.last_updated_at'));
    }

    public function test_index_runs_a_single_query_regardless_of_number_of_masters(): void
    {
        $this->actingAsEditor();

        DB::enableQueryLog();
        $this->getJson('/api/masters')->assertOk();

        // 件数・最終更新日時をマスタごとに数えると 1 + 4 回になる（N+1）
        $masterQueries = array_filter(DB::getQueryLog(), fn (array $q) => str_contains($q['query'], 'master_tables'));
        $this->assertCount(1, $masterQueries);
    }

    public function test_schema_returns_column_definitions_in_display_order(): void
    {
        $response = $this->actingAsEditor()->getJson('/api/masters/enemies/schema');

        $response->assertOk()->assertJsonCount(8, 'data');
        $columns = collect($response->json('data'))->keyBy('key');

        $this->assertSame('name', $response->json('data.0.key'));
        $this->assertSame(
            ['type' => 'string', 'is_required' => true, 'max_length' => 30],
            Arr::only($columns['name'], ['type', 'is_required', 'max_length']),
        );
        $this->assertSame(['normal', 'mid_boss', 'boss'], $columns['rank']['enum_values']);
        // JSON では 1.0 と 1 が区別されないため、型ではなく値で比べる
        $this->assertEquals([1, 9999], [$columns['hp']['min_value'], $columns['hp']['max_value']]);
        $this->assertSame('abilities', $columns['ability_id']['ref_master_key']);
        $this->assertFalse($columns['ability_id']['is_required']);
        $this->assertNull($columns['hp']['ref_master_key']);
    }

    public function test_records_returns_production_data_sorted_by_record_key(): void
    {
        $response = $this->actingAsEditor()->getJson('/api/masters/stages/records');

        $response->assertOk()
            ->assertJsonCount(12, 'data')
            ->assertJsonPath('data.0.record_key', 'st_1_1')
            ->assertJsonPath('data.0.data.name', 'はじまりの草原')
            ->assertJsonPath('data.0.data.unlock_stage_id', null)
            ->assertJsonPath('data.1.data.unlock_stage_id', 'st_1_1')
            ->assertJsonPath('data.0.updated_by.name', '管理 次郎');
    }

    public function test_unknown_master_returns_404(): void
    {
        $this->actingAsEditor()->getJson('/api/masters/unknown/schema')->assertNotFound();
        $this->actingAsEditor()->getJson('/api/masters/unknown/records')->assertNotFound();
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/masters')->assertUnauthorized();
        $this->getJson('/api/masters/enemies/schema')->assertUnauthorized();
        $this->getJson('/api/masters/enemies/records')->assertUnauthorized();
    }
}
