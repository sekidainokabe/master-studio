<?php

namespace App\Validation\Rules;

use App\Validation\Issue;
use App\Validation\MergedMasters;

/**
 * 1つのステージに置けるボス（rank=boss の敵）は1体まで（ゲーム固有のルール）。
 * 2体以上あるときは、そのステージのボスの配置すべてにエラーを出す（どれを直すかは編集者が決める）
 */
final class OneBossPerStage implements CrossRule
{
    public function check(MergedMasters $masters): iterable
    {
        $enemies = $masters->rows('enemies');

        $bossesByStage = [];
        foreach ($masters->rows('stage_enemies') as $recordKey => $placement) {
            $enemyKey = $placement['enemy_id'] ?? null;
            $enemy = is_string($enemyKey) ? ($enemies[$enemyKey] ?? null) : null;
            $stage = $placement['stage_id'] ?? null;
            if (($enemy['rank'] ?? null) === 'boss' && is_string($stage)) {
                $bossesByStage[$stage][] = (string) $recordKey;
            }
        }

        foreach ($bossesByStage as $stage => $placements) {
            $count = count($placements);
            if ($count < 2) {
                continue;
            }
            foreach ($placements as $recordKey) {
                yield new Issue('stage_enemies', $recordKey, 'enemy_id', "ステージ {$stage} にボスが{$count}体います（1体まで）");
            }
        }
    }
}
