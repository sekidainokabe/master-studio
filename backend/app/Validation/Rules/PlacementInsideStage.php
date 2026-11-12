<?php

namespace App\Validation\Rules;

use App\Validation\Issue;
use App\Validation\MergedMasters;

/**
 * 敵配置の座標が、配置先ステージの幅・高さの範囲内にあること（ゲーム固有のルール）。
 * 座標の上限はステージごとに違うため、列定義（max_value）では表せない
 */
final class PlacementInsideStage implements CrossRule
{
    private const LIMITS = [
        'pos_x' => ['width', '幅'],
        'pos_y' => ['height', '高さ'],
    ];

    public function check(MergedMasters $masters): iterable
    {
        $stages = $masters->rows('stages');

        foreach ($masters->rows('stage_enemies') as $recordKey => $placement) {
            $stageKey = $placement['stage_id'] ?? null;
            $stage = is_string($stageKey) ? ($stages[$stageKey] ?? null) : null;
            if ($stage === null) {
                continue;
            }
            foreach (self::LIMITS as $column => [$limitColumn, $label]) {
                $value = $placement[$column] ?? null;
                $limit = $stage[$limitColumn] ?? null;
                if (self::isNumber($value) && self::isNumber($limit) && $value > $limit) {
                    yield new Issue('stage_enemies', (string) $recordKey, $column, "ステージの{$label}（{$limit}）以内で入力してください");
                }
            }
        }
    }

    private static function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }
}
