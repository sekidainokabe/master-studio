<?php

namespace App\Services;

use App\Enums\ChangeOperation;
use App\Enums\ChangesetStatus;
use App\Models\Changeset;
use App\Models\ChangesetItem;
use App\Models\MasterColumn;
use App\Models\MasterRecord;
use App\Models\MasterTable;

/**
 * 変更セットの差分。「承認したら今の本番がどう変わるか」を、マスタごと・行ごと・列ごとに返す。
 *
 * 比べる相手は、変更セットを作ったときの本番（before_data）ではなく、今の本番。
 * そのため作成後に本番が変わっていれば、それも反映した結果になる（例：すでに同じ値になった列は差分に出ない）。
 * 作成後に本番が変わったこと自体を知らせる競合の検出は、承認処理（ChangesetApproval）で行う。
 * 反映済みの変更セットだけは、承認の時点の本番（before_data）と比べる。
 */
class ChangesetDiff
{
    /**
     * @return list<array{
     *     master: string,
     *     name: string,
     *     counts: array{created: int, updated: int, deleted: int},
     *     rows: list<array{record_key: string, operation: 'created'|'updated'|'deleted', changes: list<array{column: string, before: mixed, after: mixed}>}>
     * }>
     */
    public function of(Changeset $changeset): array
    {
        // 変更・マスタと列・対象の本番の行を、それぞれ1回の SQL でまとめて読み込む
        $items = $changeset->items()->get();
        if ($items->isEmpty()) {
            return [];
        }
        $tables = MasterTable::with('columns')->whereIn('id', $items->pluck('master_table_id')->unique())->orderBy('id')->get();

        // 反映済みのときは、本番がすでに変わっているため、承認の時点で残した反映前の値（before_data）と比べる
        $applied = $changeset->status === ChangesetStatus::Applied;
        $production = $applied ? collect() : MasterRecord::whereIn('master_table_id', $tables->pluck('id'))
            ->whereIn('record_key', $items->pluck('record_key')->unique())
            ->get()
            ->keyBy(fn (MasterRecord $r) => "{$r->master_table_id}:{$r->record_key}");
        $before = fn (ChangesetItem $item) => $applied
            ? $item->before_data
            : $production->get("{$item->master_table_id}:{$item->record_key}")?->data;

        $result = [];
        foreach ($tables as $table) {
            $rows = $items->where('master_table_id', $table->id)->sortBy('record_key', SORT_STRING)
                ->map(fn (ChangesetItem $item) => $this->row($table, $item, $before($item)))
                ->filter()
                ->values()
                ->all();
            if ($rows === []) {
                continue;
            }

            $result[] = [
                'master' => $table->key,
                'name' => $table->name,
                'counts' => [
                    'created' => count(array_filter($rows, fn ($r) => $r['operation'] === 'created')),
                    'updated' => count(array_filter($rows, fn ($r) => $r['operation'] === 'updated')),
                    'deleted' => count(array_filter($rows, fn ($r) => $r['operation'] === 'deleted')),
                ],
                'rows' => $rows,
            ];
        }

        return $result;
    }

    /**
     * 1行分の差分。今の本番から何も変わらない行は null
     *
     * @param  array<string, mixed>|null  $current  今の本番の値（本番にない行は null）
     * @return array{record_key: string, operation: 'created'|'updated'|'deleted', changes: list<array{column: string, before: mixed, after: mixed}>}|null
     */
    private function row(MasterTable $table, ChangesetItem $item, ?array $current): ?array
    {
        $after = $item->operation === ChangeOperation::Delete ? null : $item->after_data;

        $operation = match (true) {
            $current === null && $after === null => null, // 消す行が、本番ですでに消えている
            $current === null => 'created',
            $after === null => 'deleted',
            default => 'updated',
        };
        if ($operation === null) {
            return null;
        }

        $changes = $table->columns
            ->map(fn (MasterColumn $c) => ['column' => $c->key, 'before' => $current[$c->key] ?? null, 'after' => $after[$c->key] ?? null])
            ->reject(fn (array $c) => self::sameValue($c['before'], $c['after']))
            ->values()
            ->all();

        return $changes === [] ? null : ['record_key' => $item->record_key, 'operation' => $operation, 'changes' => $changes];
    }

    /** 数値は 1 と 1.0 を同じとみなす（JSON を通すと区別できなくなるため。ChangesetRecords と同じ考え方） */
    private static function sameValue(mixed $a, mixed $b): bool
    {
        $isNumber = fn (mixed $v) => is_int($v) || is_float($v);

        return $isNumber($a) && $isNumber($b) ? $a == $b : $a === $b;
    }
}
