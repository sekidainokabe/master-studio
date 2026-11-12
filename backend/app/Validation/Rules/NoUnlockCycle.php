<?php

namespace App\Validation\Rules;

use App\Validation\Issue;
use App\Validation\MergedMasters;

/**
 * ステージの解放条件（unlock_stage_id）が、自分自身を指したり、循環したりしないこと（ゲーム固有のルール）。
 * 循環していると、そのステージはいつまでも遊べるようにならない。エラーは循環に含まれるステージすべてに出す
 */
final class NoUnlockCycle implements CrossRule
{
    public function check(MergedMasters $masters): iterable
    {
        $stages = $masters->rows('stages');

        foreach ($stages as $start => $data) {
            $start = (string) $start;
            if (($data['unlock_stage_id'] ?? null) === $start) {
                yield new Issue('stages', $start, 'unlock_stage_id', '自分自身は指定できません');

                continue;
            }

            // 解放条件をたどり、出発点に戻ってきたら、出発点は循環の中にある
            $path = [$start];
            $current = $data['unlock_stage_id'] ?? null;
            while (is_string($current) && isset($stages[$current]) && ! in_array($current, $path, true)) {
                $path[] = $current;
                $current = $stages[$current]['unlock_stage_id'] ?? null;
            }
            if ($current === $start) {
                yield new Issue('stages', $start, 'unlock_stage_id', '解放条件が循環しています（'.implode(' → ', [...$path, $start]).'）');
            }
        }
    }
}
