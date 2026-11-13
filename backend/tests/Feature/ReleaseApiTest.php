<?php

namespace Tests\Feature;

use App\Enums\ChangesetStatus;
use App\Enums\UserRole;
use App\Models\Changeset;
use App\Models\Release;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * リリースの一覧（GET /api/releases）と JSON のダウンロード（GET /api/releases/{version}/export）
 */
class ReleaseApiTest extends TestCase
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

    /** 承認で作られたのと同じ形のリリース（v2）を、直接作る */
    private function releaseV2(): Release
    {
        $changeset = Changeset::factory()->for($this->editor, 'author')->status(ChangesetStatus::Applied)->create([
            'title' => '攻撃力の調整',
        ]);

        return Release::create([
            'version' => 2,
            'changeset_id' => $changeset->id,
            'released_by' => $this->approver->id,
            'content' => '{"version": 2}',
        ]);
    }

    public function test_新しい版から順に、変更セットと反映した人を付けて返す(): void
    {
        $v2 = $this->releaseV2();

        $response = $this->actingAs($this->editor)->getJson('/api/releases')->assertOk();

        $response->assertJsonPath('data.0.version', 2)
            ->assertJsonPath('data.0.changeset', ['id' => $v2->changeset_id, 'title' => '攻撃力の調整'])
            ->assertJsonPath('data.0.released_by.name', $this->approver->name)
            ->assertJsonPath('data.0.content_bytes', strlen('{"version": 2}'))
            // シードの v1 は変更セットを通していない
            ->assertJsonPath('data.1.version', 1)
            ->assertJsonPath('data.1.changeset', null)
            ->assertJsonPath('meta.total', 2);
        // 一覧には JSON の本文を含めない
        $this->assertArrayNotHasKey('content', $response->json('data.0'));
    }

    public function test_ログインしていなければ一覧を見られない(): void
    {
        $this->getJson('/api/releases')->assertUnauthorized();
    }

    public function test_保存したjsonをそのままダウンロードできる(): void
    {
        $content = Release::where('version', 1)->value('content');

        $response = $this->actingAs($this->editor)->get('/api/releases/1/export')->assertOk();

        $this->assertSame($content, $response->getContent());
        $this->assertStringStartsWith('application/json', $response->headers->get('Content-Type'));
        $this->assertSame('attachment; filename="release_v1.json"', $response->headers->get('Content-Disposition'));
    }

    public function test_urlは版の番号で指定する(): void
    {
        $v2 = $this->releaseV2();
        // id と版の番号がずれていても、版の番号で探す
        $v2->forceFill(['id' => 100])->save();

        $this->actingAs($this->editor)->get('/api/releases/2/export')->assertOk();
        $this->actingAs($this->editor)->getJson('/api/releases/100/export')->assertNotFound();
    }

    public function test_jsonがないリリースは404(): void
    {
        $this->releaseV2()->update(['content' => null]);

        $this->actingAs($this->editor)->getJson('/api/releases/2/export')
            ->assertNotFound();
    }
}
