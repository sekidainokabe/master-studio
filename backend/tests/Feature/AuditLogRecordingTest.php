<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\ChangesetStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Changeset;
use App\Models\Release;
use App\Models\User;
use App\Services\ReleaseExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Tests\TestCase;

/**
 * 操作履歴の記録。操作ごとに1件残り、detail に件数やコメントが入ること。失敗した操作は残さないこと。
 * detail は MySQL の JSON 型でキーの順番が変わるため、順番を問わない assertEquals で比べる
 */
class AuditLogRecordingTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->editor = User::where('role', UserRole::Editor)->firstOrFail();
        $this->approver = User::where('role', UserRole::Approver)->firstOrFail();
    }

    /** sanctum のガードは最初に見つけた利用者を覚えているため、切り替えるたびに作り直す */
    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'web');
    }

    private function newChangeset(): Changeset
    {
        return Changeset::factory()->for($this->editor, 'author')->create([
            'base_release_id' => Release::query()->orderByDesc('version')->value('id'),
        ]);
    }

    private function saveAbilities(Changeset $changeset, array $records): void
    {
        $this->as($this->editor)
            ->putJson("/api/changesets/{$changeset->id}/masters/abilities/records", ['records' => $records])
            ->assertOk();
    }

    private function ability(string $name, int $power = 10): array
    {
        return [
            'name' => $name, 'attack_type' => 'melee', 'element' => 'none', 'power' => $power,
            'range' => 1.5, 'startup_frames' => 5, 'cooldown_frames' => 30,
        ];
    }

    private function onlyLog(): AuditLog
    {
        $this->assertSame(1, AuditLog::count());

        return AuditLog::firstOrFail();
    }

    public function test_login_is_recorded_but_failed_login_is_not(): void
    {
        $this->withHeader('Referer', 'http://localhost:5173');
        $this->postJson('/api/login', ['email' => 'editor@example.com', 'password' => 'wrong'])->assertUnprocessable();
        $this->assertSame(0, AuditLog::count());

        $this->postJson('/api/login', ['email' => 'editor@example.com', 'password' => 'password'])->assertOk();

        $log = $this->onlyLog();
        $this->assertSame(AuditAction::Login, $log->action);
        $this->assertSame($this->editor->id, $log->user_id);
        $this->assertSame(['user', $this->editor->id], [$log->target_type, $log->target_id]);
    }

    public function test_changeset_create_update_and_delete_are_recorded(): void
    {
        $id = $this->as($this->editor)->postJson('/api/changesets', ['title' => 'HP の調整'])->assertCreated()->json('data.id');
        $this->as($this->editor)->patchJson("/api/changesets/{$id}", ['title' => 'HP の調整（ボス）', 'description' => null])->assertOk();
        // 何も変えない編集は残さない
        $this->as($this->editor)->patchJson("/api/changesets/{$id}", ['title' => 'HP の調整（ボス）', 'description' => null])->assertOk();
        $this->as($this->editor)->deleteJson("/api/changesets/{$id}")->assertNoContent();

        $logs = AuditLog::orderBy('id')->get();
        $this->assertSame(
            [AuditAction::ChangesetCreated, AuditAction::ChangesetUpdated, AuditAction::ChangesetDeleted],
            $logs->pluck('action')->all(),
        );
        $this->assertEquals(['title' => 'HP の調整'], $logs[0]->detail);
        $this->assertEquals(['before' => ['title' => 'HP の調整'], 'after' => ['title' => 'HP の調整（ボス）']], $logs[1]->detail);
        // 変更セットが消えても、何だったかが分かる
        $this->assertEquals(['title' => 'HP の調整（ボス）', 'item_count' => 0], $logs[2]->detail);
        $this->assertTrue($logs->every(fn (AuditLog $l) => $l->target_type === 'changeset' && $l->target_id === $id));
    }

    public function test_saving_records_keeps_counts_only(): void
    {
        $changeset = $this->newChangeset();
        $this->saveAbilities($changeset, [['record_key' => 'ab_901', 'data' => $this->ability('新技')]]);
        AuditLog::query()->delete();

        $ab001 = $this->as($this->editor)->getJson("/api/changesets/{$changeset->id}/masters/abilities/records")->json('data.0');
        $this->saveAbilities($changeset, [
            ['record_key' => 'ab_001', 'data' => [...$ab001['data'], 'power' => 999]],
            ['record_key' => 'ab_002', 'data' => null],
            ['record_key' => 'ab_902', 'data' => $this->ability('新技2')],
            // 追加した行の取り消し
            ['record_key' => 'ab_901', 'data' => null],
        ]);

        $log = $this->onlyLog();
        $this->assertSame(AuditAction::RecordsSaved, $log->action);
        $this->assertEquals(['master' => 'abilities', 'created' => 1, 'updated' => 1, 'deleted' => 1, 'reverted' => 1], $log->detail);
    }

    public function test_reverting_an_item_and_importing_csv_are_recorded(): void
    {
        $changeset = $this->newChangeset();
        $this->saveAbilities($changeset, [['record_key' => 'ab_901', 'data' => $this->ability('新技')]]);
        $itemId = $changeset->items()->value('id');
        AuditLog::query()->delete();

        $this->as($this->editor)->deleteJson("/api/changesets/{$changeset->id}/items/{$itemId}")->assertNoContent();
        $this->as($this->editor)->post(
            "/api/changesets/{$changeset->id}/masters/abilities/import",
            ['file' => UploadedFile::fake()->createWithContent('abilities.csv', "record_key,power\nab_001,500\nab_903,1\n")],
            ['Accept' => 'application/json'],
        )->assertOk();
        // 取り込めなかった CSV は残さない
        $this->as($this->editor)->post(
            "/api/changesets/{$changeset->id}/masters/abilities/import",
            ['file' => UploadedFile::fake()->createWithContent('broken.csv', "record_key,unknown\nab_001,1\n")],
            ['Accept' => 'application/json'],
        )->assertUnprocessable();

        $logs = AuditLog::orderBy('id')->get();
        $this->assertSame([AuditAction::ItemReverted, AuditAction::CsvImported], $logs->pluck('action')->all());
        $this->assertEquals(['master' => 'abilities', 'record_key' => 'ab_901', 'operation' => 'create'], $logs[0]->detail);
        $this->assertEquals(
            ['master' => 'abilities', 'filename' => 'abilities.csv', 'created' => 1, 'updated' => 1, 'unchanged' => 0],
            $logs[1]->detail,
        );
    }

    public function test_review_flow_is_recorded(): void
    {
        $changeset = $this->newChangeset();
        $this->saveAbilities($changeset, [['record_key' => 'ab_901', 'data' => $this->ability('新技')]]);
        AuditLog::query()->delete();

        $this->as($this->editor)->postJson("/api/changesets/{$changeset->id}/submit")->assertOk();
        $this->as($this->approver)->postJson("/api/changesets/{$changeset->id}/reject", ['comment' => '名前を見直してください'])->assertOk();
        $this->as($this->editor)->postJson("/api/changesets/{$changeset->id}/reopen")->assertOk();
        $this->as($this->editor)->postJson("/api/changesets/{$changeset->id}/submit")->assertOk();
        $this->as($this->approver)->postJson("/api/changesets/{$changeset->id}/approve", ['comment' => 'OK'])->assertOk();

        $logs = AuditLog::orderBy('id')->get();
        $this->assertSame(
            [AuditAction::Submitted, AuditAction::Rejected, AuditAction::Reopened, AuditAction::Submitted, AuditAction::Approved],
            $logs->pluck('action')->all(),
        );
        $this->assertSame([$this->editor->id, $this->approver->id, $this->editor->id, $this->editor->id, $this->approver->id], $logs->pluck('user_id')->all());
        $this->assertEquals(['item_count' => 1], $logs[0]->detail);
        $this->assertEquals(['comment' => '名前を見直してください'], $logs[1]->detail);
        $this->assertEquals(['base_release_version' => 1], $logs[2]->detail);
        $this->assertEquals(['version' => 2, 'item_count' => 1, 'comment' => 'OK'], $logs[4]->detail);
    }

    public function test_failed_operations_leave_no_log(): void
    {
        $changeset = $this->newChangeset();
        // 変更0件のレビュー依頼（422）
        $this->as($this->editor)->postJson("/api/changesets/{$changeset->id}/submit")->assertUnprocessable();
        $this->saveAbilities($changeset, [['record_key' => 'ab_901', 'data' => $this->ability('新技')]]);
        AuditLog::query()->delete();
        // 権限のない承認（403）
        $changeset->update(['status' => ChangesetStatus::InReview]);
        $this->as($this->editor)->postJson("/api/changesets/{$changeset->id}/approve")->assertForbidden();
        $this->assertSame(0, AuditLog::count());

        // 承認の途中で失敗したら、操作履歴も一緒に巻き戻る
        $this->mock(ReleaseExport::class, fn ($mock) => $mock->shouldReceive('content')->andThrow(new RuntimeException('出力に失敗')));
        $this->as($this->approver)->postJson("/api/changesets/{$changeset->id}/approve")->assertServerError();

        $this->assertSame(0, AuditLog::count());
    }
}
