<?php

namespace App\Validation;

use App\Validation\Rules\CrossRule;
use App\Validation\Rules\NoUnlockCycle;
use App\Validation\Rules\OneBossPerStage;
use App\Validation\Rules\PlacementInsideStage;
use App\Validation\Rules\ReferencedRowNotDeleted;
use App\Validation\Rules\RefExists;

/**
 * 変更セット全体の検証（docs/SPEC.md 5章）。変更セットを反映したあとの全マスタについて、
 * 単一セルの検証（CellValidator）と横断検証（Rules/）の結果をまとめて返す。
 *
 * 横断検証はサーバーだけで行う。他のマスタのデータが必要で、画面に全マスタを読み込ませたくないため
 */
class CrossValidator
{
    public function __construct(private readonly CellValidator $cells) {}

    /**
     * @return list<CrossRule>
     */
    private function rules(): array
    {
        return [
            new RefExists,
            new ReferencedRowNotDeleted,
            new PlacementInsideStage,
            new OneBossPerStage,
            new NoUnlockCycle,
        ];
    }

    /**
     * マスタの順 → record_key → 列の順に並べたエラーの一覧
     *
     * @return list<array{master: string, record_key: string, column: string|null, kind: 'cell'|'cross', message: string}>
     */
    public function validate(MergedMasters $masters): array
    {
        $errors = [];

        foreach ($masters->tables as $table) {
            foreach ($masters->rows($table->key) as $recordKey => $data) {
                foreach ($this->cells->validateRecord($table->columns, $data) as $column => $message) {
                    $errors[] = $this->format(new Issue($table->key, (string) $recordKey, $column, $message), 'cell');
                }
            }
        }
        foreach ($this->rules() as $rule) {
            foreach ($rule->check($masters) as $issue) {
                $errors[] = $this->format($issue, 'cross');
            }
        }

        // マスタは画面のタブの順、列は列定義の順（行全体のエラーは先頭）に並べる
        $tableOrder = $masters->tables->keys()->flip()->all();
        $columnOrder = $masters->tables->map(fn ($t) => $t->columns->pluck('sort_order', 'key')->all())->all();
        $sortKey = fn (array $e) => [
            $tableOrder[$e['master']],
            $e['record_key'],
            $e['column'] === null ? -1 : ($columnOrder[$e['master']][$e['column']] ?? PHP_INT_MAX),
        ];
        usort($errors, fn (array $a, array $b) => $sortKey($a) <=> $sortKey($b));

        return $errors;
    }

    /**
     * @param  'cell'|'cross'  $kind
     * @return array{master: string, record_key: string, column: string|null, kind: 'cell'|'cross', message: string}
     */
    private function format(Issue $issue, string $kind): array
    {
        return [
            'master' => $issue->master,
            'record_key' => $issue->recordKey,
            'column' => $issue->column,
            'kind' => $kind,
            'message' => $issue->message,
        ];
    }
}
