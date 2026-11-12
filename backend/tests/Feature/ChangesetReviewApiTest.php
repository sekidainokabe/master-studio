<?php

namespace Tests\Feature;

use App\Enums\ChangesetStatus;
use App\Enums\UserRole;
use App\Models\Changeset;
use App\Models\MasterRecord;
use App\Models\MasterTable;
use App\Models\Release;
use App\Models\User;
use App\Services\ChangesetRecords;
use App\Services\ReleaseExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * レビュー依頼・承認・却下・下書きに戻す（POST /api/changesets/{id}/submit など）
 */
class ChangesetReviewApiTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;

    private User $approver;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->editor = User::where('role', UserRole::Editor)->firstOrFail();
        $this->approver = User::where('role', UserRole::Approver)->firstOrFail();
        $this->admin = User::where('role', UserRole::Admin)->firstOrFail();
    }

    private function newChangeset(?User $author = null, ChangesetStatus $status = ChangesetStatus::Draft): Changeset
    {
        return Changeset::factory()->for($author ?? $this->editor, 'author')->status($status)->create([
            'base_release_id' => Release::query()->orderByDesc('version')->value('id'),
        ]);
    }

    private function record(string $master, string $key): ?MasterRecord
    {
        return MasterRecord::where('master_table_id', MasterTable::where('key', $master)->value('id'))
            ->where('record_key', $key)->first();
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $changes  record_key => 本番に上書きする列（null は削除）
     */
    private function change(Changeset $changeset, string $master, array $changes): void
    {
        $records = [];
        foreach ($changes as $key => $values) {
            $base = $this->record($master, $key)?->data ?? [];
            $records[] = ['record_key' => $key, 'data' => $values === null ? null : [...$base, ...$values]];
        }
        app(ChangesetRecords::class)->save($changeset, MasterTable::where('key', $master)->firstOrFail(), $records);
    }

    /** エラーのない新しい攻撃パターン */
    private function newAbility(string $name = '新技'): array
    {
        return [
            'name' => $name, 'attack_type' => 'melee', 'element' => 'fire', 'power' => 10,
            'range' => 2, 'startup_frames' => 5, 'cooldown_frames' => 30,
        ];
    }

    /** 中身のある、レビュー中の変更セット */
    private function inReview(?User $author = null): Changeset
    {
        $changeset = $this->newChangeset($author);
        $this->change($changeset, 'abilities', ['ab_001' => ['power' => 123]]);
        $changeset->update(['status' => ChangesetStatus::InReview]);

        return $changeset;
    }

    private function act(User $user, Changeset $changeset, string $action, array $body = []): TestResponse
    {
        return $this->as($user)->postJson("/api/changesets/{$changeset->id}/{$action}", $body);
    }

    /**
     * 1つのテストの中で利用者を切り替える。sanctum のガードは最初に見つけた利用者を覚えているため、ガードを作り直す
     */
    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'web');
    }

    // レビュー依頼

    public function test_author_can_submit_a_valid_changeset(): void
    {
        $changeset = $this->newChangeset();
        $this->change($changeset, 'abilities', ['ab_001' => ['power' => 123]]);

        $this->act($this->editor, $changeset, 'submit')
            ->assertOk()
            ->assertJsonPath('data.status', 'in_review')
            ->assertJsonPath('data.can.update', false);

        $this->assertNotNull($changeset->refresh()->submitted_at);
    }

    public function test_empty_or_invalid_changeset_cannot_be_submitted(): void
    {
        $changeset = $this->newChangeset();
        $this->act($this->editor, $changeset, 'submit')
            ->assertUnprocessable()
            ->assertJsonPath('errors.changeset.0', '変更が1件もありません。');

        // HP 0 の敵（単一セルのエラー）と、参照切れ（横断のエラー）
        $this->change($changeset, 'enemies', ['en_001' => ['hp' => 0, 'ability_id' => 'ab_999']]);
        $this->act($this->editor, $changeset, 'submit')
            ->assertUnprocessable()
            ->assertJsonPath('errors.changeset.0', '検証エラーが 2 件あります。編集画面で修正してください。');

        $this->assertSame(ChangesetStatus::Draft, $changeset->refresh()->status);
    }

    public function test_only_author_or_admin_can_submit_a_draft(): void
    {
        $changeset = $this->newChangeset();
        $this->change($changeset, 'abilities', ['ab_001' => ['power' => 123]]);
        $otherEditor = User::factory()->create(['role' => UserRole::Editor]);

        $this->act($otherEditor, $changeset, 'submit')->assertForbidden();
        $this->act($this->approver, $changeset, 'submit')->assertForbidden();
        $this->act($this->admin, $changeset, 'submit')->assertOk();
        // 2回目は状態が違うので 409
        $this->act($this->editor, $changeset, 'submit')
            ->assertConflict()
            ->assertJsonPath('message', '下書きの変更セットだけレビューを依頼できます。');
    }

    // 承認

    public function test_approve_applies_changes_and_creates_a_release(): void
    {
        $changeset = $this->newChangeset();
        $before = $this->record('abilities', 'ab_001')->data;
        $this->change($changeset, 'abilities', [
            'ab_001' => ['power' => 123, 'range' => 3],
            'ab_901' => $this->newAbility(),
        ]);
        // 誰からも参照されていない敵配置を消す
        $this->change($changeset, 'stage_enemies', ['se_0150' => null]);
        $this->act($this->editor, $changeset, 'submit')->assertOk();

        $this->act($this->approver, $changeset, 'approve', ['comment' => '確認しました'])
            ->assertOk()
            ->assertJsonPath('data.status', 'applied')
            ->assertJsonPath('data.reviewer.id', $this->approver->id)
            ->assertJsonPath('data.review_comment', '確認しました')
            ->assertJsonPath('data.can.approve', false);

        $release = Release::where('version', 2)->firstOrFail();
        $this->assertSame($changeset->id, $release->changeset_id);
        $this->assertSame($this->approver->id, $release->released_by);

        // 本番データ：更新・追加・削除。最終更新者は作成者、リリースは v2
        $updated = $this->record('abilities', 'ab_001');
        $this->assertSame(123, $updated->data['power']);
        $this->assertSame($this->editor->id, $updated->updated_by);
        $this->assertSame($release->id, $updated->release_id);
        $this->assertSame('新技', $this->record('abilities', 'ab_901')->data['name']);
        $this->assertNull($this->record('stage_enemies', 'se_0150'));

        // 反映前の値が before_data に残る
        $item = $changeset->items()->where('record_key', 'ab_001')->firstOrFail();
        $this->assertSame($before['power'], $item->before_data['power']);

        // 出力 JSON：反映後の本番データ。列は列定義の順、float の列は小数で出る
        $json = json_decode($release->content, true);
        $this->assertSame(2, $json['version']);
        $ab001 = collect($json['masters']['abilities'])->firstWhere('record_key', 'ab_001');
        $this->assertSame(['record_key', 'name', 'attack_type', 'element', 'power', 'range', 'startup_frames', 'cooldown_frames'], array_keys($ab001));
        $this->assertSame(123, $ab001['power']);
        $this->assertStringContainsString('"range": 3.0', $release->content);
        $this->assertCount(21, $json['masters']['abilities']);
        $this->assertNull(collect($json['masters']['stage_enemies'])->firstWhere('record_key', 'se_0150'));
    }

    public function test_seeded_initial_release_has_content(): void
    {
        $json = json_decode(Release::where('version', 1)->firstOrFail()->content, true);

        $this->assertSame(1, $json['version']);
        $this->assertSame(['abilities', 'enemies', 'stages', 'stage_enemies'], array_keys($json['masters']));
        $this->assertSame('ab_001', $json['masters']['abilities'][0]['record_key']);
    }

    public function test_nobody_can_approve_their_own_changeset(): void
    {
        $changeset = $this->inReview($this->admin);

        $this->act($this->admin, $changeset, 'approve')
            ->assertForbidden()
            ->assertJsonPath('message', '自分が作成した変更セットは承認できません。');
        // 別の人なら承認できる
        $this->act($this->approver, $changeset, 'approve')->assertOk();
    }

    public function test_editor_cannot_approve_and_draft_cannot_be_approved(): void
    {
        $this->act($this->editor, $this->inReview($this->admin), 'approve')->assertForbidden();

        $draft = $this->newChangeset();
        $this->change($draft, 'abilities', ['ab_001' => ['power' => 123]]);
        $this->act($this->approver, $draft, 'approve')
            ->assertConflict()
            ->assertJsonPath('message', 'レビュー中の変更セットだけ承認できます。');
    }

    public function test_changeset_with_errors_cannot_be_approved(): void
    {
        $changeset = $this->inReview();
        // レビュー依頼のあとに、別の変更セットで参照先が消えた、という状況を作る
        $this->change($changeset, 'enemies', ['en_001' => ['ability_id' => 'ab_999']]);

        $this->act($this->approver, $changeset, 'approve')->assertUnprocessable();

        $this->assertSame(ChangesetStatus::InReview, $changeset->refresh()->status);
        $this->assertSame(1, Release::count());
    }

    public function test_conflict_with_a_newer_release_is_rejected_without_changes(): void
    {
        $first = $this->newChangeset();
        $second = $this->newChangeset();
        foreach ([$first, $second] as $i => $changeset) {
            $this->change($changeset, 'abilities', [
                'ab_001' => ['power' => 100 + $i],
                'ab_901' => $this->newAbility("新技{$i}"),
                'ab_002' => ['power' => 200 + $i],
            ]);
            $changeset->update(['status' => ChangesetStatus::InReview]);
        }
        // 2つ目だけが変える行（競合しない）
        $this->change($second, 'abilities', ['ab_003' => ['power' => 300]]);
        $this->act($this->approver, $first, 'approve')->assertOk();

        $this->act($this->approver, $second, 'approve')
            ->assertConflict()
            ->assertJsonPath('conflicts', [
                ['master' => 'abilities', 'record_key' => 'ab_001', 'message' => '作成後に、リリース v2 で変更されました。'],
                ['master' => 'abilities', 'record_key' => 'ab_002', 'message' => '作成後に、リリース v2 で変更されました。'],
                ['master' => 'abilities', 'record_key' => 'ab_901', 'message' => '同じ record_key の行が、作成後に本番へ追加されました。'],
            ]);

        // 何も反映されない
        $this->assertSame(ChangesetStatus::InReview, $second->refresh()->status);
        $this->assertSame(100, $this->record('abilities', 'ab_001')->data['power']);
        $this->assertNotSame(300, $this->record('abilities', 'ab_003')->data['power']);
        $this->assertSame(2, Release::count());
    }

    public function test_failure_during_approval_rolls_everything_back(): void
    {
        $changeset = $this->inReview();
        $power = $this->record('abilities', 'ab_001')->data['power'];
        $this->mock(ReleaseExport::class, fn ($mock) => $mock->shouldReceive('content')->andThrow(new RuntimeException('出力に失敗')));

        $this->act($this->approver, $changeset, 'approve')->assertServerError();

        $this->assertSame($power, $this->record('abilities', 'ab_001')->data['power']);
        $this->assertSame(ChangesetStatus::InReview, $changeset->refresh()->status);
        $this->assertSame(1, Release::count());
    }

    public function test_diff_of_applied_changeset_compares_with_the_production_at_approval(): void
    {
        $changeset = $this->inReview();
        $before = $this->record('abilities', 'ab_001')->data['power'];
        $this->act($this->approver, $changeset, 'approve')->assertOk();

        $this->as($this->editor)->getJson("/api/changesets/{$changeset->id}/diff")
            ->assertOk()
            ->assertJsonPath('data.0.rows.0.changes', [['column' => 'power', 'before' => $before, 'after' => 123]]);
    }

    // 却下・下書きに戻す

    public function test_reject_requires_a_comment_and_reopen_returns_to_draft(): void
    {
        $changeset = $this->inReview();

        $this->act($this->approver, $changeset, 'reject')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['comment' => '却下の理由は必須です。']);
        $this->act($this->approver, $changeset, 'reject', ['comment' => 'HP を見直してください'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.review_comment', 'HP を見直してください')
            ->assertJsonPath('data.can.reopen', false);

        // 却下のあとに別の変更セットが反映された → 下書きに戻すと、基準のリリースが最新になる
        $other = $this->inReview($this->admin);
        $this->change($other, 'abilities', ['ab_001' => ['power' => 5]]);
        $this->act($this->approver, $other, 'approve')->assertOk();

        $this->act($this->approver, $changeset, 'reopen')->assertForbidden();
        $this->act($this->editor, $changeset, 'reopen')
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.base_release_version', 2)
            ->assertJsonPath('data.review_comment', 'HP を見直してください')
            ->assertJsonPath('data.can.update', true);
        $this->act($this->editor, $changeset, 'reopen')->assertConflict();
    }

    public function test_can_flags_follow_role_and_status(): void
    {
        $changeset = $this->inReview();

        $this->as($this->approver)->getJson("/api/changesets/{$changeset->id}")
            ->assertJsonPath('data.can', [
                'update' => false, 'delete' => false, 'submit' => false,
                'approve' => true, 'reject' => true, 'reopen' => false,
            ]);
        $this->as($this->editor)->getJson("/api/changesets/{$changeset->id}")
            ->assertJsonPath('data.can.approve', false);
    }
}
