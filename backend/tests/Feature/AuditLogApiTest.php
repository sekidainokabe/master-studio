<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Changeset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 操作履歴の一覧（GET /api/audit-logs）と、絞り込み用の利用者一覧（GET /api/users）
 */
class AuditLogApiTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;

    private User $approver;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        AuditLog::query()->delete();
        $this->editor = User::where('role', UserRole::Editor)->firstOrFail();
        $this->approver = User::where('role', UserRole::Approver)->firstOrFail();
        $this->admin = User::where('role', UserRole::Admin)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>|null  $detail
     */
    private function log(User $user, AuditAction $action, Changeset|User $target, string $createdAt = '2026-11-13 03:00:00', ?array $detail = null): AuditLog
    {
        $log = AuditLog::create([
            'user_id' => $user->id,
            'action' => $action,
            'target_type' => $target instanceof Changeset ? 'changeset' : 'user',
            'target_id' => $target->id,
            'detail' => $detail,
        ]);
        $log->forceFill(['created_at' => $createdAt])->save();

        return $log;
    }

    private function changeset(string $title = '攻撃力の調整'): Changeset
    {
        return Changeset::factory()->for($this->editor, 'author')->create(['title' => $title]);
    }

    public function test_approverとadminだけが見られる(): void
    {
        $this->actingAs($this->editor)->getJson('/api/audit-logs')->assertForbidden();
        $this->actingAs($this->editor)->getJson('/api/users')->assertForbidden();
        $this->actingAs($this->editor)->getJson('/api/me')->assertJsonPath('data.can.view_audit_logs', false);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->approver)->getJson('/api/audit-logs')->assertOk();
        $this->actingAs($this->approver)->getJson('/api/me')->assertJsonPath('data.can.view_audit_logs', true);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin)->getJson('/api/audit-logs')->assertOk();
    }

    public function test_新しい順に、利用者と操作名と対象の変更セットを付けて返す(): void
    {
        $changeset = $this->changeset();
        $deleted = $this->changeset('消した変更セット');
        $this->log($this->editor, AuditAction::Login, $this->editor);
        $this->log($this->editor, AuditAction::ChangesetCreated, $changeset, detail: ['title' => '攻撃力の調整']);
        $this->log($this->editor, AuditAction::ChangesetDeleted, $deleted, detail: ['title' => '消した変更セット', 'item_count' => 0]);
        $deleted->delete();

        $response = $this->actingAs($this->approver)->getJson('/api/audit-logs')->assertOk();

        $response->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.action', 'changeset.delete')
            ->assertJsonPath('data.0.action_label', '変更セットの削除')
            // 削除済みの変更セットはタイトルを引けない（画面では detail のタイトルを使う）
            ->assertJsonPath('data.0.target', ['type' => 'changeset', 'id' => $deleted->id, 'title' => null])
            ->assertJsonPath('data.0.detail.title', '消した変更セット')
            ->assertJsonPath('data.1.target', ['type' => 'changeset', 'id' => $changeset->id, 'title' => '攻撃力の調整'])
            ->assertJsonPath('data.1.user', ['id' => $this->editor->id, 'name' => $this->editor->name])
            ->assertJsonPath('data.2.action', 'login')
            ->assertJsonPath('data.2.target.title', null);
    }

    public function test_利用者と操作で絞り込める(): void
    {
        $changeset = $this->changeset();
        $this->log($this->editor, AuditAction::Login, $this->editor);
        $this->log($this->editor, AuditAction::Submitted, $changeset);
        $this->log($this->approver, AuditAction::Login, $this->approver);

        $this->actingAs($this->approver)->getJson("/api/audit-logs?user_id={$this->editor->id}")
            ->assertJsonPath('meta.total', 2);
        $this->actingAs($this->approver)->getJson('/api/audit-logs?action=login')
            ->assertJsonPath('meta.total', 2);
        $this->actingAs($this->approver)->getJson("/api/audit-logs?user_id={$this->editor->id}&action=changeset.submit")
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.action', 'changeset.submit');
    }

    public function test_期間は画面から受け取った日時で絞り込む(): void
    {
        // 日本時間の 11/13 は、UTC の 11/12 15:00 から 11/13 15:00 まで
        $this->log($this->editor, AuditAction::Login, $this->editor, '2026-11-12 14:59:59');
        $inside = $this->log($this->editor, AuditAction::Login, $this->editor, '2026-11-12 15:00:00');
        $this->log($this->editor, AuditAction::Login, $this->editor, '2026-11-13 15:00:00');

        $query = http_build_query(['from' => '2026-11-13T00:00:00+09:00', 'to' => '2026-11-14T00:00:00+09:00']);
        $this->actingAs($this->approver)->getJson("/api/audit-logs?{$query}")
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $inside->id);
    }

    public function test_おかしな条件は422(): void
    {
        $this->actingAs($this->approver)->getJson('/api/audit-logs?action=unknown')
            ->assertUnprocessable()->assertJsonValidationErrors('action');

        $query = http_build_query(['from' => '2026-11-14T00:00:00+09:00', 'to' => '2026-11-13T00:00:00+09:00']);
        $this->actingAs($this->approver)->getJson("/api/audit-logs?{$query}")
            ->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_利用者一覧はメールアドレスを返さない(): void
    {
        $response = $this->actingAs($this->approver)->getJson('/api/users')->assertOk();

        $response->assertJsonPath('data.0', ['id' => $this->editor->id, 'name' => $this->editor->name, 'role' => 'editor']);
        $this->assertCount(User::count(), $response->json('data'));
    }
}
