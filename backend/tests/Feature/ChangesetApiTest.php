<?php

namespace Tests\Feature;

use App\Enums\ChangeOperation;
use App\Enums\ChangesetStatus;
use App\Enums\UserRole;
use App\Models\Changeset;
use App\Models\MasterTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChangesetApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // ユーザー3人と初期リリース（version 1）を用意する
        $this->seed();
    }

    private function user(UserRole $role): User
    {
        return User::where('role', $role)->firstOrFail();
    }

    public function test_editor_can_create_a_draft_based_on_latest_release(): void
    {
        $editor = $this->user(UserRole::Editor);

        $response = $this->actingAs($editor, 'web')->postJson('/api/changesets', [
            'title' => 'ボスの HP を調整',
            'description' => 'ワールド1のボスが硬すぎるため',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'ボスの HP を調整')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.author.name', $editor->name)
            ->assertJsonPath('data.reviewer', null)
            ->assertJsonPath('data.base_release_version', 1)
            ->assertJsonPath('data.item_count', 0);
        $this->assertDatabaseHas('changesets', ['title' => 'ボスの HP を調整', 'author_id' => $editor->id]);
    }

    public function test_admin_can_create(): void
    {
        $this->actingAs($this->user(UserRole::Admin), 'web')
            ->postJson('/api/changesets', ['title' => '管理者の変更'])
            ->assertCreated();
    }

    public function test_approver_cannot_create(): void
    {
        $this->actingAs($this->user(UserRole::Approver), 'web')
            ->postJson('/api/changesets', ['title' => '承認者の変更'])
            ->assertForbidden();

        $this->assertDatabaseCount('changesets', 0);
    }

    public function test_title_is_required_and_limited_to_200_characters(): void
    {
        $editor = $this->user(UserRole::Editor);

        $this->actingAs($editor, 'web')->postJson('/api/changesets', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title' => 'タイトルは必須です。']);

        // メッセージは日本語（lang/ja/validation.php）で、項目名も日本語になる
        $this->actingAs($editor, 'web')->postJson('/api/changesets', ['title' => str_repeat('あ', 201)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title' => 'タイトルは200文字以内で入力してください。']);

        $this->actingAs($editor, 'web')->postJson('/api/changesets', ['title' => str_repeat('あ', 200)])
            ->assertCreated();
    }

    public function test_index_is_paginated_newest_first(): void
    {
        $changesets = Changeset::factory()->count(25)->create();

        $page1 = $this->actingAs($this->user(UserRole::Editor), 'web')->getJson('/api/changesets');
        $page1->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('data.0.id', $changesets->last()->id)
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson('/api/changesets?page=2')->assertOk()->assertJsonCount(5, 'data');
    }

    public function test_index_can_filter_by_status_and_author(): void
    {
        $editor = $this->user(UserRole::Editor);
        Changeset::factory()->count(2)->create(['author_id' => $editor->id]);
        Changeset::factory()->status(ChangesetStatus::InReview)->create(['author_id' => $editor->id]);
        Changeset::factory()->status(ChangesetStatus::InReview)->create();

        $this->actingAs($editor, 'web');

        $this->getJson('/api/changesets?status=in_review')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/changesets?author_id={$editor->id}")->assertOk()->assertJsonCount(3, 'data');
        $this->getJson("/api/changesets?status=in_review&author_id={$editor->id}")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_index_rejects_unknown_status(): void
    {
        $this->actingAs($this->user(UserRole::Editor), 'web')
            ->getJson('/api/changesets?status=unknown')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_show_returns_detail(): void
    {
        $changeset = Changeset::factory()->create(['title' => '敵配置の見直し']);

        $this->actingAs($this->user(UserRole::Approver), 'web')
            ->getJson("/api/changesets/{$changeset->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $changeset->id)
            ->assertJsonPath('data.title', '敵配置の見直し')
            ->assertJsonPath('data.item_count', 0);
    }

    public function test_show_unknown_returns_generic_404(): void
    {
        $this->actingAs($this->user(UserRole::Editor), 'web')
            ->getJson('/api/changesets/999999')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not found.']);
    }

    // 1つのテストで actingAs を切り替えると、認証済みのユーザーが前のまま残るため、権限ごとにテストを分ける
    public function test_me_tells_editor_can_create_changesets(): void
    {
        $this->actingAs($this->user(UserRole::Editor), 'web')
            ->getJson('/api/me')->assertJsonPath('data.can.create_changeset', true);
    }

    public function test_me_tells_approver_cannot_create_changesets(): void
    {
        $this->actingAs($this->user(UserRole::Approver), 'web')
            ->getJson('/api/me')->assertJsonPath('data.can.create_changeset', false);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/changesets')->assertUnauthorized();
        $this->postJson('/api/changesets', ['title' => 'x'])->assertUnauthorized();
    }

    public function test_show_tells_author_can_update_and_delete_draft(): void
    {
        $editor = $this->user(UserRole::Editor);
        $changeset = Changeset::factory()->for($editor, 'author')->create();

        $this->actingAs($editor, 'web')
            ->getJson("/api/changesets/{$changeset->id}")
            ->assertJsonPath('data.can.update', true)
            ->assertJsonPath('data.can.delete', true);
    }

    public function test_show_tells_approver_cannot_update(): void
    {
        $changeset = Changeset::factory()->for($this->user(UserRole::Editor), 'author')->create();

        $this->actingAs($this->user(UserRole::Approver), 'web')
            ->getJson("/api/changesets/{$changeset->id}")
            ->assertJsonPath('data.can.update', false)
            ->assertJsonPath('data.can.delete', false);
    }

    public function test_author_can_update_title_and_description(): void
    {
        $editor = $this->user(UserRole::Editor);
        $changeset = Changeset::factory()->for($editor, 'author')->create(['description' => '元の説明']);

        $this->actingAs($editor, 'web')
            ->patchJson("/api/changesets/{$changeset->id}", ['title' => '新しいタイトル'])
            ->assertOk()
            ->assertJsonPath('data.title', '新しいタイトル')
            // 送らなかった項目は変わらない
            ->assertJsonPath('data.description', '元の説明');
    }

    public function test_update_validates_title(): void
    {
        $editor = $this->user(UserRole::Editor);
        $changeset = Changeset::factory()->for($editor, 'author')->create();

        $this->actingAs($editor, 'web')
            ->patchJson("/api/changesets/{$changeset->id}", ['title' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title']);
    }

    public function test_other_editor_cannot_update(): void
    {
        $changeset = Changeset::factory()->create();

        $this->actingAs($this->user(UserRole::Editor), 'web')
            ->patchJson("/api/changesets/{$changeset->id}", ['title' => '他人の変更'])
            ->assertForbidden();
    }

    public function test_cannot_update_after_submission(): void
    {
        $editor = $this->user(UserRole::Editor);
        $changeset = Changeset::factory()->for($editor, 'author')->status(ChangesetStatus::InReview)->create();

        $this->actingAs($editor, 'web')
            ->patchJson("/api/changesets/{$changeset->id}", ['title' => 'レビュー中の変更'])
            ->assertStatus(409);
    }

    public function test_author_can_delete_draft_with_its_items(): void
    {
        $editor = $this->user(UserRole::Editor);
        $changeset = Changeset::factory()->for($editor, 'author')->create();
        $changeset->items()->create([
            'master_table_id' => MasterTable::where('key', 'abilities')->value('id'),
            'record_key' => 'ab_901',
            'operation' => ChangeOperation::Create,
            'after_data' => ['name' => '新技'],
        ]);

        $this->actingAs($editor, 'web')
            ->deleteJson("/api/changesets/{$changeset->id}")
            ->assertNoContent();

        $this->assertModelMissing($changeset);
        $this->assertDatabaseCount('changeset_items', 0);
    }

    public function test_approver_cannot_delete(): void
    {
        $changeset = Changeset::factory()->create();

        $this->actingAs($this->user(UserRole::Approver), 'web')
            ->deleteJson("/api/changesets/{$changeset->id}")
            ->assertForbidden();
        $this->assertModelExists($changeset);
    }

    public function test_cannot_delete_applied_changeset(): void
    {
        $admin = $this->user(UserRole::Admin);
        $changeset = Changeset::factory()->status(ChangesetStatus::Applied)->create();

        $this->actingAs($admin, 'web')
            ->deleteJson("/api/changesets/{$changeset->id}")
            ->assertStatus(409);
        $this->assertModelExists($changeset);
    }
}
