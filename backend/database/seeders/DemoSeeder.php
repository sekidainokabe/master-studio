<?php

namespace Database\Seeders;

use App\Enums\AuditAction;
use App\Enums\ChangesetStatus;
use App\Enums\UserRole;
use App\Models\Changeset;
use App\Models\MasterRecord;
use App\Models\MasterTable;
use App\Models\Release;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ChangesetApproval;
use App\Services\ChangesetRecords;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * デモ用のデータ（docs/SPEC.md 4章・11章）。初期データ（DatabaseSeeder）を入れたあとに使う。
 *
 * 1. 反映済みの変更セット：画面と同じサービス（保存 → 依頼 → 承認）を通して作るので、v2 のリリースと操作履歴もできる
 * 2. 下書きの変更セット：わざとエラーを4種類含める。デモでは編集者がこれを直してレビューを依頼する
 *
 * 本番の値に変更を重ねて作るため、初期データから本番が変わっていても動く。
 * 使う行が本番にないときは、途中まで入れずに例外で止める（呼ぶ側のトランザクションで巻き戻す）
 */
class DemoSeeder extends Seeder
{
    /** 入れ済みかどうかの目印。反映済みの変更セットは削除できないので、消えることがない */
    public const APPLIED_TITLE = '序盤の敵の調整（デモ）';

    public const DRAFT_TITLE = 'ワールド1のバランス調整（デモ）';

    public function __construct(
        private readonly ChangesetRecords $records,
        private readonly ChangesetApproval $approval,
        private readonly AuditLogger $audit,
    ) {}

    public function run(): void
    {
        $editor = User::where('role', UserRole::Editor)->orderBy('id')->firstOrFail();
        $approver = User::where('role', UserRole::Approver)->orderBy('id')->firstOrFail();

        $this->applied($editor, $approver);
        $this->draft($editor);
    }

    private function applied(User $editor, User $approver): void
    {
        $changeset = $this->newChangeset($editor, self::APPLIED_TITLE, 'ホノコウモリがすぐ倒れてしまうため、HP を上げます。あわせて、ホノコウモリの火の玉を少し強くします。');
        $this->change($editor, $changeset, 'abilities', ['ab_008' => ['power' => 22]]);
        $this->change($editor, $changeset, 'enemies', ['en_009' => ['hp' => 7]]);

        $this->approval->assertReady($changeset);
        $changeset->update(['status' => ChangesetStatus::InReview, 'submitted_at' => now()]);
        $this->audit->record($editor, AuditAction::Submitted, $changeset, ['item_count' => $changeset->items()->count()]);

        $this->approval->approve($changeset, $approver, '確認しました。ワールド1の難しさが上がりすぎないことも確かめています。');
    }

    private function draft(User $editor): void
    {
        $changeset = $this->newChangeset($editor, self::DRAFT_TITLE, "ワールド1の後半を少し手ごわくします。\n（デモ用：検証エラーを4種類わざと含めています）");

        // 1. HP が 0 の敵（HP は 1〜9999）
        $this->change($editor, $changeset, 'enemies', ['en_001' => ['hp' => 0]]);
        // 2. ステージ外にはみ出した敵配置（はじまりの草原は幅 3200）
        $this->change($editor, $changeset, 'stage_enemies', ['se_0001' => ['pos_x' => 3500.0]]);
        // 3. ボスが2体いるステージ（火山のふもとには、もともとボスのヴォルカがいる）
        $this->add($editor, $changeset, 'stage_enemies', 'se_demo_boss', [
            'stage_id' => 'st_1_4',
            'enemy_id' => 'en_029',
            'pos_x' => 4000.0,
            'pos_y' => 1016.0,
            'spawn_frame' => 0,
            'respawn' => false,
        ]);
        // 4. 敵（アワフキ）が参照している攻撃パターン（三連バブル）を削除する
        $this->change($editor, $changeset, 'abilities', ['ab_012' => null]);
    }

    private function newChangeset(User $author, string $title, string $description): Changeset
    {
        $changeset = Changeset::create([
            'title' => $title,
            'description' => $description,
            'status' => ChangesetStatus::Draft,
            'author_id' => $author->id,
            'base_release_id' => Release::query()->orderByDesc('version')->value('id'),
        ]);
        $this->audit->record($author, AuditAction::ChangesetCreated, $changeset, ['title' => $title]);

        return $changeset;
    }

    /**
     * 本番の行に一部の列を上書きして保存する。null は削除
     *
     * @param  array<string, array<string, mixed>|null>  $changes  record_key => 上書きする列
     */
    private function change(User $editor, Changeset $changeset, string $master, array $changes): void
    {
        $table = MasterTable::where('key', $master)->firstOrFail();
        $production = MasterRecord::where('master_table_id', $table->id)
            ->whereIn('record_key', array_keys($changes))
            ->pluck('data', 'record_key');

        $records = [];
        foreach ($changes as $recordKey => $columns) {
            $current = $production->get($recordKey) ?? throw new RuntimeException("デモ用データに使う {$master} / {$recordKey} が本番にありません。");
            $records[] = ['record_key' => $recordKey, 'data' => $columns === null ? null : [...$current, ...$columns]];
        }

        $this->save($editor, $changeset, $table, $records);
    }

    /**
     * 本番にない行を追加する
     *
     * @param  array<string, mixed>  $data
     */
    private function add(User $editor, Changeset $changeset, string $master, string $recordKey, array $data): void
    {
        $table = MasterTable::where('key', $master)->firstOrFail();
        if (MasterRecord::where('master_table_id', $table->id)->where('record_key', $recordKey)->exists()) {
            throw new RuntimeException("デモ用データで追加する {$master} / {$recordKey} が、すでに本番にあります。");
        }

        $this->save($editor, $changeset, $table, [['record_key' => $recordKey, 'data' => $data]]);
    }

    /**
     * 画面からの保存（ChangesetRecordController）と同じく、保存して操作履歴に件数を残す
     *
     * @param  list<array{record_key: string, data: array<string, mixed>|null}>  $records
     */
    private function save(User $editor, Changeset $changeset, MasterTable $table, array $records): void
    {
        $counts = $this->records->save($changeset, $table, $records);
        $this->audit->record($editor, AuditAction::RecordsSaved, $changeset, ['master' => $table->key, ...$counts]);
    }
}
