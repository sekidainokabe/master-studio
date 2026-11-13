<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\ChangesetStatus;
use App\Models\AuditLog;
use App\Models\Changeset;
use App\Models\MasterRecord;
use App\Models\MasterTable;
use App\Models\Release;
use App\Validation\CrossValidator;
use App\Validation\MergedMasters;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * デモ用データ（php artisan app:seed-demo）
 */
class SeedDemoTest extends TestCase
{
    use RefreshDatabase;

    private function record(string $master, string $key): ?MasterRecord
    {
        return MasterRecord::where('master_table_id', MasterTable::where('key', $master)->value('id'))
            ->where('record_key', $key)->first();
    }

    public function test_反映済みの変更セットと_v2_のリリースができる(): void
    {
        $this->seed();

        $this->artisan('app:seed-demo')->assertSuccessful();

        $applied = Changeset::where('title', DemoSeeder::APPLIED_TITLE)->firstOrFail();
        $this->assertSame(ChangesetStatus::Applied, $applied->status);
        $release = Release::where('version', 2)->firstOrFail();
        $this->assertSame($applied->id, $release->changeset_id);
        $this->assertNotNull($release->content);
        $this->assertSame(7, $this->record('enemies', 'en_009')->data['hp']);
        $this->assertSame(22, $this->record('abilities', 'ab_008')->data['power']);

        // 画面から操作したときと同じ操作履歴が残る
        $this->assertEquals(
            [AuditAction::ChangesetCreated, AuditAction::RecordsSaved, AuditAction::RecordsSaved, AuditAction::Submitted, AuditAction::Approved],
            AuditLog::where('target_id', $applied->id)->where('target_type', 'changeset')->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_下書きには4種類の検証エラーがある(): void
    {
        $this->seed();

        $this->artisan('app:seed-demo')->assertSuccessful();

        $draft = Changeset::where('title', DemoSeeder::DRAFT_TITLE)->firstOrFail();
        $this->assertSame(ChangesetStatus::Draft, $draft->status);
        // 下書きは v2 を基準にしているので、承認しても反映済みの変更セットとは競合しない
        $this->assertSame(2, $draft->baseRelease->version);

        $errors = collect(app(CrossValidator::class)->validate(MergedMasters::of($draft)));
        $byRow = $errors->map(fn (array $e) => "{$e['master']}/{$e['record_key']}/{$e['kind']}")->unique()->values()->all();

        $this->assertEqualsCanonicalizing([
            // HP が 0 の敵
            'enemies/en_001/cell',
            // 削除する攻撃パターンを参照している（エラーは削除する側の行に出る）
            'abilities/ab_012/cross',
            // ステージ外の敵配置
            'stage_enemies/se_0001/cross',
            // ボスが2体（両方の配置に出る）
            'stage_enemies/se_0046/cross',
            'stage_enemies/se_demo_boss/cross',
        ], $byRow);
        // 本番はまだ変わっていない
        $this->assertSame(3, $this->record('enemies', 'en_001')->data['hp']);
    }

    public function test_何度実行しても1回分だけ入る(): void
    {
        $this->seed();

        $this->artisan('app:seed-demo')->assertSuccessful();
        $this->artisan('app:seed-demo')->expectsOutputToContain('入れ済み')->assertSuccessful();

        $this->assertSame(2, Changeset::count());
        $this->assertSame(2, Release::count());
    }

    public function test_初期データがなければ何もしない(): void
    {
        $this->artisan('app:seed-demo')->assertSuccessful();

        $this->assertSame(0, Changeset::count());
    }

    public function test_使う行が本番になければ、途中まで入れずに失敗する(): void
    {
        $this->seed();
        // 下書きで使う行を消しておく（反映済みの変更セットは作れるが、下書きで失敗する）
        $this->record('stage_enemies', 'se_0001')->delete();

        $this->expectException(\RuntimeException::class);
        try {
            $this->artisan('app:seed-demo')->run();
        } finally {
            $this->assertSame(0, Changeset::count());
            $this->assertSame(1, Release::count());
        }
    }
}
