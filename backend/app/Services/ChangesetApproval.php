<?php

namespace App\Services;

use App\Enums\ChangeOperation;
use App\Enums\ChangesetStatus;
use App\Exceptions\ChangesetConflictException;
use App\Models\Changeset;
use App\Models\ChangesetItem;
use App\Models\MasterRecord;
use App\Models\MasterTable;
use App\Models\Release;
use App\Models\User;
use App\Validation\CrossValidator;
use App\Validation\MergedMasters;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * 変更セットの承認と本番への反映（docs/SPEC.md 9章）。
 *
 * 1つのトランザクションで「ロック → 競合の確認 → 再検証 → 本番へ反映 → リリース作成と JSON 出力」を行い、
 * 途中で失敗したらすべて巻き戻す（本番データが半分だけ変わった状態を残さない）。
 */
class ChangesetApproval
{
    public function __construct(
        private readonly CrossValidator $validator,
        private readonly ReleaseExport $export,
    ) {}

    /**
     * レビュー依頼・承認の前提。変更が1件以上あり、検証エラーが0件であること。満たさなければ 422
     */
    public function assertReady(Changeset $changeset): void
    {
        if (! $changeset->items()->exists()) {
            throw ValidationException::withMessages(['changeset' => '変更が1件もありません。']);
        }

        $errors = $this->validator->validate(MergedMasters::of($changeset));
        if ($errors !== []) {
            throw ValidationException::withMessages([
                'changeset' => '検証エラーが '.count($errors).' 件あります。編集画面で修正してください。',
            ]);
        }
    }

    public function approve(Changeset $changeset, User $approver, ?string $comment): Release
    {
        return DB::transaction(function () use ($changeset, $approver, $comment) {
            // 承認どうしを1件ずつ順に処理させる。横断検証は全マスタを見るため、対象の行だけでなく全体を止める必要がある。
            // 本番データを書き換えるのは承認だけなので、編集や閲覧は止まらない。
            // ロックの順番は「マスタ → 変更セット」にそろえる（却下などは変更セットだけをロックするので、互いに待ち合っても止まらない）
            MasterTable::query()->orderBy('id')->lockForUpdate()->get();
            $changeset = Changeset::whereKey($changeset->id)->lockForUpdate()->firstOrFail();

            // ロックを取ってから状態を確かめ直す（同じ変更セットを2人が同時に承認しても、2回目は 409 になる）
            Gate::forUser($approver)->authorize('approve', $changeset);

            $items = $changeset->items()->with('masterTable:id,key')->get();
            $production = $this->productionOf($items);

            $this->assertNoConflict($changeset, $items, $production);
            $this->assertReady($changeset);

            $releasedAt = now();
            $release = new Release([
                'version' => (int) Release::max('version') + 1,
                'changeset_id' => $changeset->id,
                'released_by' => $approver->id,
            ]);
            // 出力 JSON の released_at と同じ時刻にする
            $release->created_at = $releasedAt;
            $release->save();

            // 変更は多くても数十〜数百行のため、1行ずつ反映する
            foreach ($items as $item) {
                $this->applyItem($changeset, $release, $item, $production->get(self::key($item)));
            }

            $release->update(['content' => $this->export->content($release->version, $releasedAt)]);

            $changeset->update([
                'status' => ChangesetStatus::Applied,
                'reviewer_id' => $approver->id,
                'review_comment' => $comment,
                'reviewed_at' => $releasedAt,
            ]);

            return $release;
        });
    }

    /**
     * 変更の対象になっている本番の行（マスタの id:record_key => 行）
     *
     * @param  Collection<int, ChangesetItem>  $items
     * @return Collection<string, MasterRecord>
     */
    private function productionOf(Collection $items): Collection
    {
        return MasterRecord::whereIn('master_table_id', $items->pluck('master_table_id')->unique())
            ->whereIn('record_key', $items->pluck('record_key')->unique())
            ->get()
            ->keyBy(fn (MasterRecord $r) => "{$r->master_table_id}:{$r->record_key}");
    }

    /**
     * 変更セットを作ったあとに、本番の同じ行が変わっていないか（競合の検出）
     *
     * @param  Collection<int, ChangesetItem>  $items
     * @param  Collection<string, MasterRecord>  $production
     */
    private function assertNoConflict(Changeset $changeset, Collection $items, Collection $production): void
    {
        $baseVersion = $changeset->baseRelease?->version ?? 0;
        // 作成後のリリース（id => version）
        $newer = Release::where('version', '>', $baseVersion)->pluck('version', 'id');

        $conflicts = [];
        foreach ($items->sortBy(fn (ChangesetItem $i) => [$i->master_table_id, $i->record_key]) as $item) {
            $record = $production->get(self::key($item));

            $message = match (true) {
                $item->operation === ChangeOperation::Create && $record !== null => '同じ record_key の行が、作成後に本番へ追加されました。',
                $item->operation !== ChangeOperation::Create && $record === null => '作成後に、本番から削除されました。',
                $record !== null && $newer->has($record->release_id) => "作成後に、リリース v{$newer[$record->release_id]} で変更されました。",
                default => null,
            };
            if ($message !== null) {
                $conflicts[] = ['master' => $item->masterTable->key, 'record_key' => $item->record_key, 'message' => $message];
            }
        }

        if ($conflicts !== []) {
            throw new ChangesetConflictException($conflicts);
        }
    }

    private function applyItem(Changeset $changeset, Release $release, ChangesetItem $item, ?MasterRecord $record): void
    {
        // 反映前の本番の値を残す。反映後は本番が変わるため、差分はこの値と比べて表示する
        $item->update(['before_data' => $record?->data]);

        // updated_by は変更を作った人（作成者）。承認した人はリリースの released_by に残る
        match ($item->operation) {
            ChangeOperation::Create => MasterRecord::create([
                'master_table_id' => $item->master_table_id,
                'record_key' => $item->record_key,
                'data' => $item->after_data,
                'updated_by' => $changeset->author_id,
                'release_id' => $release->id,
            ]),
            ChangeOperation::Update => $record->update([
                'data' => $item->after_data,
                'updated_by' => $changeset->author_id,
                'release_id' => $release->id,
            ]),
            ChangeOperation::Delete => $record->delete(),
        };
    }

    private static function key(ChangesetItem $item): string
    {
        return "{$item->master_table_id}:{$item->record_key}";
    }
}
