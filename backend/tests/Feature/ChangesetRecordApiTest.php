<?php

namespace Tests\Feature;

use App\Enums\ChangeOperation;
use App\Enums\ChangesetStatus;
use App\Enums\UserRole;
use App\Models\Changeset;
use App\Models\ChangesetItem;
use App\Models\MasterRecord;
use App\Models\MasterTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ChangesetRecordApiTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;

    private Changeset $changeset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->editor = $this->user(UserRole::Editor);
        $this->changeset = Changeset::factory()->for($this->editor, 'author')->create();
    }

    private function user(UserRole $role): User
    {
        return User::where('role', $role)->firstOrFail();
    }

    /** 本番の攻撃パターン1件の data */
    private function ability(string $key): array
    {
        $tableId = MasterTable::where('key', 'abilities')->value('id');

        return MasterRecord::where('master_table_id', $tableId)->where('record_key', $key)->firstOrFail()->data;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private function save(array $records, ?User $as = null, ?Changeset $changeset = null): TestResponse
    {
        $changeset ??= $this->changeset;

        return $this->actingAs($as ?? $this->editor, 'web')
            ->putJson("/api/changesets/{$changeset->id}/masters/abilities/records", ['records' => $records]);
    }

    /** レスポンスの行を record_key で引けるようにする */
    private function rowsByKey(TestResponse $response): array
    {
        return collect($response->json('data'))->keyBy('record_key')->all();
    }

    public function test_index_returns_production_rows_when_nothing_is_changed(): void
    {
        $response = $this->actingAs($this->user(UserRole::Approver), 'web')
            ->getJson("/api/changesets/{$this->changeset->id}/masters/abilities/records");

        $response->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('data.0.record_key', 'ab_001')
            ->assertJsonPath('data.0.status', 'unchanged')
            ->assertJsonPath('data.0.item_id', null)
            ->assertJsonPath('data.0.data', $this->ability('ab_001'))
            ->assertJsonPath('data.0.original', $this->ability('ab_001'));
        // エラーがないときも、配列（[]）ではなくオブジェクト（{}）で返す
        $this->assertStringContainsString('"errors":{}', $response->getContent());
    }

    public function test_update_records_change_against_production(): void
    {
        $before = $this->ability('ab_001');

        $rows = $this->rowsByKey($this->save([
            ['record_key' => 'ab_001', 'data' => [...$before, 'power' => 123]],
        ])->assertOk());

        $this->assertSame('updated', $rows['ab_001']['status']);
        $this->assertSame(123, $rows['ab_001']['data']['power']);
        $this->assertSame($before['power'], $rows['ab_001']['original']['power']);
        $this->assertSame('unchanged', $rows['ab_002']['status']);

        $item = ChangesetItem::sole();
        $this->assertSame($rows['ab_001']['item_id'], $item->id);
        $this->assertSame(ChangeOperation::Update, $item->operation);
        $this->assertEquals($before, $item->before_data);
        // 本番データは変わらない
        $this->assertSame($before, $this->ability('ab_001'));
    }

    public function test_create_saves_rule_violations_and_returns_them_as_errors(): void
    {
        $rows = $this->rowsByKey($this->save([
            ['record_key' => 'ab_901', 'data' => ['power' => 5000, 'attack_type' => 'melee']],
        ])->assertOk());

        $row = $rows['ab_901'];
        $this->assertSame('created', $row['status']);
        $this->assertNull($row['original']);
        // 送られなかった列は null で埋める（MySQL の JSON 列はキーの順を並べ替えるため、順番は比べない）
        $this->assertEqualsCanonicalizing(
            ['name', 'attack_type', 'element', 'power', 'range', 'startup_frames', 'cooldown_frames'],
            array_keys($row['data']),
        );
        $this->assertNull($row['data']['name']);
        $this->assertSame('必須です', $row['errors']['name']);
        $this->assertSame('0〜999 の範囲で入力してください', $row['errors']['power']);
        $this->assertSame(ChangeOperation::Create, ChangesetItem::sole()->operation);
    }

    public function test_delete_keeps_production_values_for_display(): void
    {
        $rows = $this->rowsByKey($this->save([['record_key' => 'ab_002', 'data' => null]])->assertOk());

        $this->assertSame('deleted', $rows['ab_002']['status']);
        $this->assertSame($this->ability('ab_002'), $rows['ab_002']['data']);
        $this->assertSame([], $rows['ab_002']['errors']);
        $this->assertSame(ChangeOperation::Delete, ChangesetItem::sole()->operation);
    }

    public function test_saving_production_values_again_removes_the_change(): void
    {
        $original = $this->ability('ab_003');
        $this->save([['record_key' => 'ab_003', 'data' => [...$original, 'power' => 1]]])->assertOk();

        // range は本番では 1.0 などの float。画面から送ると 1 になるが、同じ値とみなす
        $sent = [...$original, 'range' => is_float($original['range']) && floor($original['range']) === $original['range'] ? (int) $original['range'] : $original['range']];
        $rows = $this->rowsByKey($this->save([['record_key' => 'ab_003', 'data' => $sent]])->assertOk());

        $this->assertSame('unchanged', $rows['ab_003']['status']);
        $this->assertSame(0, ChangesetItem::count());
    }

    public function test_deleting_a_created_row_removes_it_entirely(): void
    {
        $this->save([['record_key' => 'ab_901', 'data' => ['name' => '新技']]])->assertOk();

        $response = $this->save([['record_key' => 'ab_901', 'data' => null]])->assertOk();

        $this->assertArrayNotHasKey('ab_901', $this->rowsByKey($response));
        $this->assertSame(0, ChangesetItem::count());
    }

    public function test_admin_can_edit_others_changeset(): void
    {
        $this->save([['record_key' => 'ab_901', 'data' => ['name' => '管理者']]], $this->user(UserRole::Admin))->assertOk();
    }

    public function test_other_editor_cannot_edit(): void
    {
        $other = User::factory()->create(['role' => UserRole::Editor]);

        $this->save([['record_key' => 'ab_901', 'data' => ['name' => '他人']]], $other)
            ->assertForbidden()
            ->assertJsonPath('message', 'この変更セットを編集する権限がありません。');
    }

    public function test_approver_cannot_edit(): void
    {
        $this->save([['record_key' => 'ab_901', 'data' => ['name' => '承認者']]], $this->user(UserRole::Approver))
            ->assertForbidden();
    }

    public function test_only_draft_can_be_edited(): void
    {
        $this->changeset->update(['status' => ChangesetStatus::InReview]);

        $this->save([['record_key' => 'ab_901', 'data' => ['name' => 'レビュー中']]])
            ->assertStatus(409)
            ->assertJsonPath('message', '下書きの変更セットだけ編集できます。');
        $this->assertSame(0, ChangesetItem::count());
    }

    public function test_rejects_input_that_cannot_be_stored(): void
    {
        $this->save([
            ['record_key' => 'AB-1', 'data' => []],
            ['record_key' => 'ab_901', 'data' => ['unknown' => 1]],
            ['record_key' => 'ab_901', 'data' => []],
        ])->assertUnprocessable()->assertJsonValidationErrors(['records.0.record_key', 'records.1.record_key', 'records.2.record_key']);

        $this->save([['record_key' => 'ab_901', 'data' => ['unknown' => 1]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['records.0.data' => '存在しない列です：unknown']);

        $this->save([['record_key' => 'ab_901', 'data' => ['name' => ['nested']]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['records.0.data.name']);

        $this->assertSame(0, ChangesetItem::count());
    }

    public function test_unknown_master_returns_404(): void
    {
        $this->actingAs($this->editor, 'web')
            ->getJson("/api/changesets/{$this->changeset->id}/masters/unknown/records")
            ->assertNotFound();
    }

    public function test_destroy_item_reverts_a_single_change(): void
    {
        $this->save([
            ['record_key' => 'ab_001', 'data' => null],
            ['record_key' => 'ab_002', 'data' => null],
        ])->assertOk();
        $item = ChangesetItem::where('record_key', 'ab_001')->sole();

        $this->actingAs($this->editor, 'web')
            ->deleteJson("/api/changesets/{$this->changeset->id}/items/{$item->id}")
            ->assertNoContent();

        $this->assertSame(['ab_002'], ChangesetItem::pluck('record_key')->all());
    }

    public function test_destroy_item_of_another_changeset_returns_404(): void
    {
        $other = Changeset::factory()->for($this->editor, 'author')->create();
        $this->save([['record_key' => 'ab_001', 'data' => null]], changeset: $other)->assertOk();

        $this->actingAs($this->editor, 'web')
            ->deleteJson("/api/changesets/{$this->changeset->id}/items/".ChangesetItem::sole()->id)
            ->assertNotFound();
    }

    public function test_approver_cannot_destroy_item(): void
    {
        // 1つのテストで actingAs を2回使うと、最初のユーザーのままになるため、変更は直接作る
        $item = $this->changeset->items()->create([
            'master_table_id' => MasterTable::where('key', 'abilities')->value('id'),
            'record_key' => 'ab_001',
            'operation' => ChangeOperation::Delete,
            'before_data' => $this->ability('ab_001'),
        ]);

        $this->actingAs($this->user(UserRole::Approver), 'web')
            ->deleteJson("/api/changesets/{$this->changeset->id}/items/{$item->id}")
            ->assertForbidden();
        $this->assertModelExists($item);
    }
}
