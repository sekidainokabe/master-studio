<?php

namespace Tests\Feature;

use App\Enums\ColumnType;
use App\Enums\UserRole;
use App\Models\MasterColumn;
use App\Models\MasterRecord;
use App\Models\MasterTable;
use App\Models\Release;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * シードデータが SPEC の制約を満たしているかを確かめる。
 * 列ごとの制約は列定義（master_columns）から読み取って検査する。
 */
class SeedDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // $seed = true は「テスト実行全体で最初に DB を作り直したとき」しか効かず、
        // 他のテストクラスが先に走ると種が入らないため、各テストのトランザクション内で毎回シードする
        $this->seed();
    }

    public function test_seeds_one_user_per_role(): void
    {
        $roles = User::pluck('role')->map(fn (UserRole $role) => $role->value)->sort()->values()->all();

        $this->assertSame(['admin', 'approver', 'editor'], $roles);
    }

    public function test_seeds_expected_number_of_records_in_initial_release(): void
    {
        $this->assertSame(1, Release::count());
        $this->assertSame(
            ['abilities' => 20, 'enemies' => 30, 'stages' => 12, 'stage_enemies' => 150],
            MasterTable::withCount('records')->pluck('records_count', 'key')->all(),
        );
        $this->assertSame(0, MasterRecord::where('release_id', '!=', Release::first()->id)->count());
    }

    public function test_every_value_satisfies_its_column_definition(): void
    {
        $recordKeysByTableId = MasterRecord::all()->groupBy('master_table_id')->map->pluck('record_key');

        foreach (MasterTable::with(['columns', 'records'])->get() as $table) {
            $columnKeys = $table->columns->pluck('key')->sort()->values()->all();

            foreach ($table->records as $record) {
                $label = "{$table->key}.{$record->record_key}";
                $data = $record->data;
                ksort($data);
                $this->assertSame($columnKeys, array_keys($data), "{$label} の列が定義と一致しない");

                foreach ($table->columns as $column) {
                    $this->assertValueMatchesColumn($column, $data[$column->key], $recordKeysByTableId, "{$label}.{$column->key}");
                }
            }
        }
    }

    public function test_stage_enemies_are_inside_their_stage(): void
    {
        $stages = $this->recordsOf('stages');

        foreach ($this->recordsOf('stage_enemies') as $key => $placement) {
            $stage = $stages[$placement['stage_id']];
            $this->assertLessThanOrEqual($stage['width'], $placement['pos_x'], "{$key} が横にはみ出している");
            $this->assertLessThanOrEqual($stage['height'], $placement['pos_y'], "{$key} が縦にはみ出している");
        }
    }

    public function test_each_stage_has_at_most_one_boss(): void
    {
        $enemies = $this->recordsOf('enemies');

        $bossCounts = $this->recordsOf('stage_enemies')
            ->filter(fn (array $placement) => $enemies[$placement['enemy_id']]['rank'] === 'boss')
            ->countBy('stage_id');

        $this->assertNotEmpty($bossCounts);
        $this->assertSame([], $bossCounts->filter(fn (int $count) => $count > 1)->all());
    }

    public function test_stage_unlock_chain_has_no_self_reference_or_cycle(): void
    {
        $stages = $this->recordsOf('stages');

        foreach ($stages as $key => $stage) {
            $visited = [$key];
            $next = $stage['unlock_stage_id'];
            while ($next !== null) {
                $this->assertNotContains($next, $visited, "{$key} の解放条件が循環している");
                $visited[] = $next;
                $next = $stages[$next]['unlock_stage_id'];
            }
        }
    }

    /**
     * @param  Collection<int, Collection<int, string>>  $recordKeysByTableId
     */
    private function assertValueMatchesColumn(MasterColumn $column, mixed $value, Collection $recordKeysByTableId, string $label): void
    {
        if ($value === null) {
            $this->assertFalse($column->is_required, "{$label} は必須");

            return;
        }

        match ($column->type) {
            ColumnType::Int => $this->assertIsInt($value, $label),
            ColumnType::Float => $this->assertTrue(is_int($value) || is_float($value), "{$label} は数値"),
            ColumnType::String => $this->assertIsString($value, $label),
            ColumnType::Bool => $this->assertIsBool($value, $label),
            ColumnType::Enum => $this->assertContains($value, $column->enum_values, $label),
            ColumnType::Ref => $this->assertContains($value, $recordKeysByTableId[$column->ref_master_table_id], "{$label} の参照先がない"),
        };

        if ($column->min_value !== null) {
            $this->assertGreaterThanOrEqual($column->min_value, $value, $label);
        }
        if ($column->max_value !== null) {
            $this->assertLessThanOrEqual($column->max_value, $value, $label);
        }
        if ($column->max_length !== null) {
            $this->assertLessThanOrEqual($column->max_length, mb_strlen($value), $label);
        }
    }

    /**
     * @return Collection<string, array<string, mixed>> record_key => data
     */
    private function recordsOf(string $tableKey): Collection
    {
        return MasterTable::where('key', $tableKey)->firstOrFail()
            ->records->mapWithKeys(fn (MasterRecord $record) => [$record->record_key => $record->data]);
    }
}
