<?php

namespace App\Validation\Rules;

use App\Enums\ColumnType;
use App\Validation\Issue;
use App\Validation\MergedMasters;

/**
 * 他の行から参照されている行は削除できない（スキーマから決まる、どのマスタにも効くルール）。
 * エラーは削除する行に出し、どこから参照されているかをメッセージに含める。
 * 参照している側の行も同じ変更セットで削除するなら、問題ないので数えない
 */
final class ReferencedRowNotDeleted implements CrossRule
{
    /** メッセージに並べる参照元の数 */
    private const SHOW = 3;

    public function check(MergedMasters $masters): iterable
    {
        $keyById = $masters->tables->mapWithKeys(fn ($t) => [$t->id => $t->key]);

        // 削除する行ごとに、参照している行を集める（マスタの key => record_key => list<"マスタ名 record_key">）
        $referrers = [];
        foreach ($masters->tables as $table) {
            foreach ($table->columns->where('type', ColumnType::Ref) as $column) {
                $refKey = $keyById[$column->ref_master_table_id];
                $deleted = $masters->deleted($refKey);
                if ($deleted === []) {
                    continue;
                }
                foreach ($masters->rows($table->key) as $recordKey => $data) {
                    $value = $data[$column->key] ?? null;
                    if (is_string($value) && isset($deleted[$value])) {
                        $referrers[$refKey][$value][] = "{$table->name} {$recordKey}";
                    }
                }
            }
        }

        foreach ($referrers as $master => $byKey) {
            foreach ($byKey as $recordKey => $from) {
                $list = implode('、', array_slice($from, 0, self::SHOW));
                $more = count($from) > self::SHOW ? ' ほか'.(count($from) - self::SHOW).'件' : '';
                yield new Issue($master, (string) $recordKey, null, "参照されているため削除できません（{$list}{$more}）");
            }
        }
    }
}
