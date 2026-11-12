<?php

namespace App\Validation;

use App\Enums\ChangeOperation;
use App\Models\Changeset;
use App\Models\ChangesetItem;
use App\Models\MasterRecord;
use App\Models\MasterTable;
use Illuminate\Support\Collection;

/**
 * 変更セットを反映したあとの、全マスタのデータ（横断検証・差分で使う）。
 *
 * 列・本番データ・変更セットの変更を、それぞれ1回の SQL でまとめて読み込み、あとはメモリ上で扱う。
 * そのため SQL の回数はマスタや行の数によらず一定になる（行ごとに SQL を発行しない）。
 */
final class MergedMasters
{
    /**
     * @param  Collection<string, MasterTable>  $tables  key => マスタ（columns を読み込み済み）
     * @param  array<string, array<string, array<string, mixed>>>  $rows  マスタの key => record_key => data（削除する行は含めない）
     * @param  array<string, array<string, array<string, mixed>>>  $deleted  マスタの key => record_key => 削除前の data
     */
    private function __construct(
        public readonly Collection $tables,
        private readonly array $rows,
        private readonly array $deleted,
    ) {}

    public static function of(Changeset $changeset): self
    {
        $tables = MasterTable::with(['columns', 'records'])->orderBy('id')->get()->keyBy('key');
        $items = $changeset->items()->get()->groupBy('master_table_id');

        $rows = [];
        $deleted = [];
        foreach ($tables as $key => $table) {
            $rows[$key] = $table->records
                ->mapWithKeys(fn (MasterRecord $r) => [$r->record_key => $r->data])
                ->all();
            $deleted[$key] = [];

            /** @var ChangesetItem $item */
            foreach ($items->get($table->id, []) as $item) {
                if ($item->operation === ChangeOperation::Delete) {
                    // 本番の行が別のリリースで先に消えていることもあるため、保存時の値（before_data）で補う
                    $deleted[$key][$item->record_key] = $rows[$key][$item->record_key] ?? $item->before_data;
                    unset($rows[$key][$item->record_key]);
                } else {
                    $rows[$key][$item->record_key] = $item->after_data;
                }
            }
            ksort($rows[$key], SORT_STRING);
            ksort($deleted[$key], SORT_STRING);
            // 本番データは以後使わないので、メモリから外す
            $table->unsetRelation('records');
        }

        return new self($tables, $rows, $deleted);
    }

    /**
     * 反映後に残る行（削除する行は含めない）
     *
     * @return array<string, array<string, mixed>> record_key => data
     */
    public function rows(string $table): array
    {
        return $this->rows[$table] ?? [];
    }

    /**
     * この変更セットで削除する行
     *
     * @return array<string, array<string, mixed>> record_key => 削除前の data
     */
    public function deleted(string $table): array
    {
        return $this->deleted[$table] ?? [];
    }
}
