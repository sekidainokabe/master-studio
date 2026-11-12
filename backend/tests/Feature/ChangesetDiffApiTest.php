<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Changeset;
use App\Models\MasterRecord;
use App\Models\MasterTable;
use App\Models\User;
use App\Services\ChangesetRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 変更セットの差分（GET /api/changesets/{id}/diff）。今の本番と比べた、追加・更新・削除の行と変わった列
 */
class ChangesetDiffApiTest extends TestCase
{
    use RefreshDatabase;

    private Changeset $changeset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->changeset = Changeset::factory()
            ->for(User::where('role', UserRole::Editor)->firstOrFail(), 'author')
            ->create();
    }

    private function record(string $master, string $key): MasterRecord
    {
        return MasterRecord::where('master_table_id', MasterTable::where('key', $master)->value('id'))
            ->where('record_key', $key)->firstOrFail();
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $changes  record_key => 本番に上書きする列（null は削除）
     */
    private function change(string $master, array $changes): void
    {
        $records = [];
        foreach ($changes as $key => $values) {
            $base = MasterRecord::where('master_table_id', MasterTable::where('key', $master)->value('id'))
                ->where('record_key', $key)->first()?->data ?? [];
            $records[] = ['record_key' => $key, 'data' => $values === null ? null : [...$base, ...$values]];
        }
        app(ChangesetRecords::class)->save($this->changeset, MasterTable::where('key', $master)->firstOrFail(), $records);
    }

    private function diff(): array
    {
        return $this->actingAs(User::where('role', UserRole::Approver)->firstOrFail(), 'web')
            ->getJson("/api/changesets/{$this->changeset->id}/diff")
            ->assertOk()
            ->json('data');
    }

    public function test_empty_changeset_has_no_diff(): void
    {
        $this->assertSame([], $this->diff());
    }

    public function test_update_lists_only_changed_columns(): void
    {
        $before = $this->record('abilities', 'ab_001')->data;
        $this->change('abilities', ['ab_001' => ['power' => $before['power'] + 1, 'name' => '強い体当たり']]);

        $this->assertSame([[
            'master' => 'abilities',
            'name' => '攻撃パターン',
            'counts' => ['created' => 0, 'updated' => 1, 'deleted' => 0],
            'rows' => [[
                'record_key' => 'ab_001',
                'operation' => 'updated',
                // 列定義の順（name → power）
                'changes' => [
                    ['column' => 'name', 'before' => $before['name'], 'after' => '強い体当たり'],
                    ['column' => 'power', 'before' => $before['power'], 'after' => $before['power'] + 1],
                ],
            ]],
        ]], $this->diff());
    }

    public function test_created_row_lists_values_that_are_set(): void
    {
        $this->change('abilities', ['ab_901' => ['name' => '新技', 'power' => 10]]);

        $row = $this->diff()[0]['rows'][0];
        $this->assertSame('created', $row['operation']);
        // 空欄（null）の列は、本番にない状態と同じなので出さない
        $this->assertSame([
            ['column' => 'name', 'before' => null, 'after' => '新技'],
            ['column' => 'power', 'before' => null, 'after' => 10],
        ], $row['changes']);
    }

    public function test_deleted_row_lists_current_production_values(): void
    {
        $current = $this->record('stage_enemies', 'se_0001')->data;
        $this->change('stage_enemies', ['se_0001' => null]);

        $diff = $this->diff()[0];
        $this->assertSame(['created' => 0, 'updated' => 0, 'deleted' => 1], $diff['counts']);
        $this->assertSame('deleted', $diff['rows'][0]['operation']);
        $this->assertEquals(
            ['column' => 'stage_id', 'before' => $current['stage_id'], 'after' => null],
            $diff['rows'][0]['changes'][0],
        );
        // respawn が false でも「値がある」ので、削除される値として出す
        $this->assertContains('respawn', array_column($diff['rows'][0]['changes'], 'column'));
    }

    public function test_masters_are_in_tab_order_and_rows_in_key_order(): void
    {
        $this->change('enemies', ['en_002' => ['hp' => 99], 'en_001' => ['hp' => 99]]);
        $this->change('abilities', ['ab_001' => ['power' => 1]]);

        $diff = $this->diff();
        $this->assertSame(['abilities', 'enemies'], array_column($diff, 'master'));
        $this->assertSame(['en_001', 'en_002'], array_column($diff[1]['rows'], 'record_key'));
    }

    public function test_compares_with_current_production_not_with_the_time_of_saving(): void
    {
        $this->change('abilities', ['ab_001' => ['power' => 123], 'ab_002' => ['power' => 456]]);

        // 保存したあとに、別のリリースで本番が変わった場合を作る
        $ab001 = $this->record('abilities', 'ab_001');
        $ab001->update(['data' => [...$ab001->data, 'power' => 123]]);
        $ab002 = $this->record('abilities', 'ab_002');
        $ab002->update(['data' => [...$ab002->data, 'power' => 400]]);

        $rows = $this->diff()[0]['rows'];
        // ab_001 は今の本番と同じ値になったので、差分に出ない
        $this->assertSame(['ab_002'], array_column($rows, 'record_key'));
        $this->assertSame([['column' => 'power', 'before' => 400, 'after' => 456]], $rows[0]['changes']);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson("/api/changesets/{$this->changeset->id}/diff")->assertUnauthorized();
    }
}
