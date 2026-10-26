<?php

namespace Tests\Feature;

use App\Enums\ChangesetStatus;
use App\Enums\UserRole;
use App\Models\Changeset;
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
            ->assertJsonValidationErrors(['title']);

        $this->actingAs($editor, 'web')->postJson('/api/changesets', ['title' => str_repeat('あ', 201)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title']);

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
}
