<?php

namespace App\Services;

use App\Enums\ColumnType;
use App\Models\MasterColumn;
use App\Models\MasterRecord;
use App\Models\MasterTable;
use Carbon\CarbonInterface;

/**
 * リリースの出力 JSON（ゲームが読み込む想定のデータ）を、今の本番データから作る。
 *
 * 承認のトランザクションの中で、本番へ反映した直後に呼ぶ。作った文字列は releases.content に保存する。
 * MySQL の JSON 型はキーの順番を並べ替えるため、列の並び（sort_order）を保てるよう文字列のまま保存する。
 */
class ReleaseExport
{
    public function content(int $version, CarbonInterface $releasedAt): string
    {
        // マスタと列・本番データを、それぞれ1回の SQL でまとめて読み込む
        $tables = MasterTable::with(['columns', 'records'])->orderBy('id')->get();

        $masters = [];
        foreach ($tables as $table) {
            $masters[$table->key] = $table->records
                ->sortBy('record_key', SORT_STRING)
                ->map(fn (MasterRecord $r) => [
                    'record_key' => $r->record_key,
                    ...$table->columns->mapWithKeys(fn (MasterColumn $c) => [$c->key => self::cast($c, $r->data[$c->key] ?? null)])->all(),
                ])
                ->values()
                ->all();
        }

        return json_encode(
            ['version' => $version, 'released_at' => $releasedAt->toIso8601String(), 'masters' => $masters],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * float の列は、整数の値も 3.0 のように小数で出す（読み込む側が列ごとに同じ型で扱えるように）
     */
    private static function cast(MasterColumn $column, mixed $value): mixed
    {
        return $column->type === ColumnType::Float && is_int($value) ? (float) $value : $value;
    }
}
