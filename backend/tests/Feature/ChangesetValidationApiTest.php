<?php

namespace Tests\Feature;

use App\Enums\ChangeOperation;
use App\Enums\UserRole;
use App\Models\Changeset;
use App\Models\MasterRecord;
use App\Models\MasterTable;
use App\Models\User;
use App\Services\ChangesetRecords;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 変更セット全体の検証（GET /api/changesets/{id}/validation）。横断検証はルールごとに、エラーになる例とならない例を確かめる
 */
class ChangesetValidationApiTest extends TestCase
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

    /** 本番の1行の data */
    private function production(string $master, string $key): array
    {
        return MasterRecord::where('master_table_id', MasterTable::where('key', $master)->value('id'))
            ->where('record_key', $key)->firstOrFail()->data;
    }

    /**
     * 本番のすべての行（record_key => data）
     *
     * @return array<string, array<string, mixed>>
     */
    private function all(string $master): array
    {
        return MasterTable::where('key', $master)->firstOrFail()->records
            ->mapWithKeys(fn (MasterRecord $r) => [$r->record_key => $r->data])->all();
    }

    /**
     * 変更セットに保存する。$changes は本番の値に上書きする列（null なら行の削除）
     *
     * @param  array<string, array<string, mixed>|null>  $changes  record_key => 列の値
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

    /** @return array{errors: list<array<string, mixed>>, counts: array<string, int>} */
    private function validation(): array
    {
        return $this->actingAs(User::where('role', UserRole::Approver)->firstOrFail(), 'web')
            ->getJson("/api/changesets/{$this->changeset->id}/validation")
            ->assertOk()
            ->json('data');
    }

    /**
     * @return list<string> 指定した行・列のエラーメッセージ
     */
    private function messages(string $master, string $key, ?string $column): array
    {
        return collect($this->validation()['errors'])
            ->where('master', $master)->where('record_key', $key)->where('column', $column)
            ->pluck('message')->all();
    }

    public function test_production_seed_has_no_errors(): void
    {
        $this->assertSame(
            ['errors' => [], 'counts' => ['total' => 0, 'cell' => 0, 'cross' => 0]],
            $this->validation(),
        );
    }

    public function test_cell_errors_are_included_with_kind(): void
    {
        $this->change('abilities', ['ab_001' => ['power' => 5000]]);

        $this->assertSame([[
            'master' => 'abilities',
            'record_key' => 'ab_001',
            'column' => 'power',
            'kind' => 'cell',
            'message' => '0〜999 の範囲で入力してください',
        ]], $this->validation()['errors']);
    }

    public function test_ref_must_exist(): void
    {
        $this->change('enemies', ['en_001' => ['ability_id' => 'ab_999']]);

        $result = $this->validation();
        $this->assertSame(['参照先がありません：ab_999'], $this->messages('enemies', 'en_001', 'ability_id'));
        $this->assertSame(['total' => 1, 'cell' => 0, 'cross' => 1], $result['counts']);
    }

    public function test_ref_to_a_row_created_in_the_same_changeset_is_valid(): void
    {
        $this->change('abilities', ['ab_901' => $this->production('abilities', 'ab_001')]);
        $this->change('enemies', ['en_001' => ['ability_id' => 'ab_901']]);

        $this->assertSame([], $this->validation()['errors']);
    }

    public function test_referenced_row_cannot_be_deleted(): void
    {
        $users = collect($this->all('enemies'))->where('ability_id', 'ab_001')->keys()->sort()->values();
        $this->assertNotEmpty($users);

        $this->change('abilities', ['ab_001' => null]);

        $messages = $this->messages('abilities', 'ab_001', null);
        $this->assertCount(1, $messages);
        $this->assertStringStartsWith("参照されているため削除できません（敵 {$users[0]}", $messages[0]);
        // 参照している側にはエラーを出さない（同じ問題を2回数えない）
        $this->assertSame([], $this->messages('enemies', $users[0], 'ability_id'));
    }

    public function test_deleting_a_row_together_with_its_referrers_is_valid(): void
    {
        // 配置の少ない敵を選び、その配置もまとめて削除する
        $placements = collect($this->all('stage_enemies'))->groupBy('enemy_id', preserveKeys: true)->sortBy->count()->first();
        $enemy = $placements->first()['enemy_id'];

        $this->change('stage_enemies', $placements->map(fn () => null)->all());
        $this->change('enemies', [$enemy => null]);

        $this->assertSame([], $this->validation()['errors']);
    }

    public function test_placement_must_be_inside_its_stage(): void
    {
        $placement = $this->production('stage_enemies', 'se_0001');
        $stage = $this->production('stages', $placement['stage_id']);

        $this->change('stage_enemies', ['se_0001' => ['pos_x' => $stage['width'] + 1, 'pos_y' => (float) $stage['height']]]);

        $this->assertSame(["ステージの幅（{$stage['width']}）以内で入力してください"], $this->messages('stage_enemies', 'se_0001', 'pos_x'));
        // 端ちょうどは範囲内
        $this->assertSame([], $this->messages('stage_enemies', 'se_0001', 'pos_y'));
    }

    public function test_shrinking_a_stage_flags_placements_of_another_master(): void
    {
        $placements = collect($this->all('stage_enemies'))->where('stage_id', 'st_1_1');
        $minX = (int) ceil($placements->max('pos_x')) - 1;

        $this->change('stages', ['st_1_1' => ['width' => max($minX, 640)]]);

        $errors = collect($this->validation()['errors']);
        $this->assertNotEmpty($errors);
        $this->assertSame(['stage_enemies'], $errors->pluck('master')->unique()->values()->all());
        $this->assertSame(['pos_x'], $errors->pluck('column')->unique()->values()->all());
    }

    public function test_at_most_one_boss_per_stage(): void
    {
        $enemies = $this->all('enemies');
        $placements = collect($this->all('stage_enemies'));
        $boss = $placements->first(fn (array $p) => $enemies[$p['enemy_id']]['rank'] === 'boss');
        $bossKey = $placements->search($boss);
        $normalKey = $placements->search(fn (array $p) => $p['stage_id'] === $boss['stage_id'] && $enemies[$p['enemy_id']]['rank'] === 'normal');

        $this->change('stage_enemies', [$normalKey => ['enemy_id' => $boss['enemy_id']]]);

        $message = "ステージ {$boss['stage_id']} にボスが2体います（1体まで）";
        $this->assertSame([$message], $this->messages('stage_enemies', $bossKey, 'enemy_id'));
        $this->assertSame([$message], $this->messages('stage_enemies', $normalKey, 'enemy_id'));
    }

    public function test_stage_cannot_unlock_itself(): void
    {
        $this->change('stages', ['st_1_2' => ['unlock_stage_id' => 'st_1_2']]);

        $this->assertSame(['自分自身は指定できません'], $this->messages('stages', 'st_1_2', 'unlock_stage_id'));
    }

    public function test_unlock_chain_cannot_be_a_cycle(): void
    {
        // 本番は st_1_1 ← st_1_2 ← st_1_3 の順に解放される。st_1_1 の解放条件を st_1_3 にすると循環する
        $this->change('stages', ['st_1_1' => ['unlock_stage_id' => 'st_1_3']]);

        $errors = collect($this->validation()['errors']);
        $this->assertSame(['st_1_1', 'st_1_2', 'st_1_3'], $errors->pluck('record_key')->all());
        $this->assertSame('解放条件が循環しています（st_1_1 → st_1_3 → st_1_2 → st_1_1）', $errors[0]['message']);
        // 循環の先にあるステージ（st_1_4）は、自分の設定が原因ではないのでエラーにしない
    }

    public function test_number_of_queries_does_not_grow_with_rows(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->validation();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $before = $count();

        // 4つのマスタに合わせて60行の変更を加える
        foreach (['abilities' => 'ab_9', 'enemies' => 'en_9', 'stages' => 'st_9_', 'stage_enemies' => 'se_9'] as $master => $prefix) {
            $first = array_key_first($this->all($master));
            $rows = [];
            for ($i = 10; $i < 25; $i++) {
                $rows["{$prefix}{$i}"] = $this->production($master, $first);
            }
            $this->change($master, $rows);
        }

        $this->assertSame($before, $count());
    }

    public function test_record_key_is_unique_within_a_changeset_and_master(): void
    {
        // 同じ行への変更は1件にまとまる（重ねたときに同じ record_key の行が2つできない）
        $item = [
            'master_table_id' => MasterTable::where('key', 'abilities')->value('id'),
            'record_key' => 'ab_901',
            'operation' => ChangeOperation::Create,
            'after_data' => ['name' => '新技'],
        ];
        $this->changeset->items()->create($item);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->changeset->items()->create($item);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson("/api/changesets/{$this->changeset->id}/validation")->assertUnauthorized();
    }
}
