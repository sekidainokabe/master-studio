<?php

namespace App\Services;

use App\Enums\ChangeOperation;
use App\Models\Changeset;
use App\Models\ChangesetItem;
use App\Models\MasterColumn;
use App\Models\MasterRecord;
use App\Models\MasterTable;
use App\Validation\CellValidator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 変更セットの中の、1つのマスタの編集用データを扱う。
 *
 * 本番データ（master_records）はそのままにして、変更セットの中の変更（changeset_items）だけを記録する。
 * 画面には「本番データに変更を重ねたもの」を返し、保存のときは本番との違いから操作（追加・更新・削除）を決める。
 */
class ChangesetRecords
{
    public function __construct(private readonly CellValidator $validator) {}

    /**
     * 本番データに変更を重ねた、編集用の行の一覧（record_key 順）
     *
     * @return list<array<string, mixed>>
     */
    public function rows(Changeset $changeset, MasterTable $table): array
    {
        $columns = $table->columns;
        $production = $table->records->keyBy('record_key');
        $items = $this->itemsOf($changeset, $table);

        $keys = $production->keys()->merge($items->keys())->unique()->sort()->values();

        return $keys->map(function (string $key) use ($columns, $production, $items) {
            /** @var MasterRecord|null $record */
            $record = $production->get($key);
            /** @var ChangesetItem|null $item */
            $item = $items->get($key);

            $status = match ($item?->operation) {
                ChangeOperation::Create => 'created',
                ChangeOperation::Update => 'updated',
                ChangeOperation::Delete => 'deleted',
                null => 'unchanged',
            };
            // 削除の記録は、本番の行が別のリリースで先に消えていることもあるため、保存時の値（before_data）で補う
            $data = $item !== null && $item->operation !== ChangeOperation::Delete
                ? $item->after_data
                : ($record?->data ?? $item?->before_data);
            // 削除する行は本番に残らないので、検証しない
            $errors = $status === 'deleted' ? [] : $this->validator->validateRecord($columns, $data);

            return [
                'record_key' => $key,
                'status' => $status,
                'item_id' => $item?->id,
                'data' => $data,
                // 変更前の本番の値（セルごとの変更を色分けするため）。変更セットで追加した行は null
                'original' => $record?->data,
                // 空の配列は JSON で [] になるため、オブジェクト（{}）にそろえる
                'errors' => (object) $errors,
            ];
        })->all();
    }

    /**
     * 複数行をまとめて保存する。data が null の行は削除する。
     * ルール違反の値もそのまま保存し、結果は rows() のエラーで返す（docs/plan-week4.md）
     *
     * @param  list<array{record_key: string, data: array<string, mixed>|null}>  $records
     */
    public function save(Changeset $changeset, MasterTable $table, array $records): void
    {
        DB::transaction(function () use ($changeset, $table, $records) {
            // 同じ変更セットへの保存が同時に来たとき、1つずつ順に処理させる
            Changeset::whereKey($changeset->id)->lockForUpdate()->first();

            $columns = $table->columns;
            $production = $table->records()->whereIn('record_key', array_column($records, 'record_key'))->get()->keyBy('record_key');
            $items = $this->itemsOf($changeset, $table);

            foreach ($records as ['record_key' => $key, 'data' => $data]) {
                $this->saveOne($changeset, $table, $key, $data === null ? null : $this->normalize($columns, $data), $production->get($key), $items->get($key));
            }

            $changeset->touch();
        });
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private function saveOne(Changeset $changeset, MasterTable $table, string $key, ?array $data, ?MasterRecord $record, ?ChangesetItem $item): void
    {
        [$operation, $before] = match (true) {
            // 本番にない行の削除 = 変更セットで追加した行の取り消し。記録ごと消す
            $data === null && $record === null => [null, null],
            $data === null => [ChangeOperation::Delete, $record->data],
            $record === null => [ChangeOperation::Create, null],
            // 本番と同じ値に戻したら、変更は無かったことにする
            $this->sameData($table->columns, $data, $record->data) => [null, null],
            default => [ChangeOperation::Update, $record->data],
        };

        if ($operation === null) {
            $item?->delete();

            return;
        }

        ChangesetItem::updateOrCreate(
            ['changeset_id' => $changeset->id, 'master_table_id' => $table->id, 'record_key' => $key],
            ['operation' => $operation, 'before_data' => $before, 'after_data' => $data],
        );
    }

    /**
     * 送られてこなかった列を null で埋め、どの行も同じ列を持つようにする
     *
     * @param  Collection<int, MasterColumn>  $columns
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(Collection $columns, array $data): array
    {
        return $columns->mapWithKeys(fn (MasterColumn $c) => [$c->key => $data[$c->key] ?? null])->all();
    }

    /**
     * 値が同じかどうか。数値は 1 と 1.0 を同じとみなす（JSON を通すと区別できなくなるため）。
     * CSV の取り込みで「変化なし」の行を数えるときにも使う
     *
     * @param  Collection<int, MasterColumn>  $columns
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    public function sameData(Collection $columns, array $a, array $b): bool
    {
        return $columns->every(function (MasterColumn $c) use ($a, $b) {
            $x = $a[$c->key] ?? null;
            $y = $b[$c->key] ?? null;

            return is_int($x) || is_float($x) ? (is_int($y) || is_float($y)) && $x == $y : $x === $y;
        });
    }

    /**
     * @return Collection<string, ChangesetItem>
     */
    private function itemsOf(Changeset $changeset, MasterTable $table): Collection
    {
        return $changeset->items()->where('master_table_id', $table->id)->get()->keyBy('record_key');
    }
}
