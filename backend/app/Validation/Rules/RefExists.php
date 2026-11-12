<?php

namespace App\Validation\Rules;

use App\Enums\ColumnType;
use App\Validation\Issue;
use App\Validation\MergedMasters;

/**
 * 参照列（type=ref）の値が、参照先マスタに存在すること（スキーマから決まる、どのマスタにも効くルール）。
 * 同じ変更セットで追加した行は、存在するものとして認める。
 * この変更セットで削除する行を参照している場合は、削除する側の行にエラーを出すため（ReferencedRowNotDeleted）、ここでは出さない
 */
final class RefExists implements CrossRule
{
    public function check(MergedMasters $masters): iterable
    {
        $keyById = $masters->tables->mapWithKeys(fn ($t) => [$t->id => $t->key]);

        foreach ($masters->tables as $table) {
            foreach ($table->columns->where('type', ColumnType::Ref) as $column) {
                $refKey = $keyById[$column->ref_master_table_id];
                $targets = $masters->rows($refKey);
                $deleted = $masters->deleted($refKey);

                foreach ($masters->rows($table->key) as $recordKey => $data) {
                    $value = $data[$column->key] ?? null;
                    if (! is_string($value) || $value === '' || isset($targets[$value]) || isset($deleted[$value])) {
                        continue;
                    }
                    yield new Issue($table->key, (string) $recordKey, $column->key, "参照先がありません：{$value}");
                }
            }
        }
    }
}
