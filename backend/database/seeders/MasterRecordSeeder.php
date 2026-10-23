<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\MasterRecord;
use App\Models\MasterTable;
use App\Models\Release;
use App\Models\User;
use Illuminate\Database\Seeder;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * 本番データの初期値と、それを反映した初期リリース（version 1）。名称はすべてオリジナル。
 */
class MasterRecordSeeder extends Seeder
{
    // 乱数の種を固定し、何回シードしても同じ敵配置になるようにする
    private const RANDOM_SEED = 20261026;

    private Release $release;

    private User $admin;

    public function run(): void
    {
        $this->admin = User::where('role', UserRole::Admin)->firstOrFail();
        $this->release = Release::create(['version' => 1, 'released_by' => $this->admin->id]);

        $this->insert('abilities', 'ab', self::abilities(), [
            'name', 'attack_type', 'element', 'power', 'range', 'startup_frames', 'cooldown_frames',
        ]);
        $this->insert('enemies', 'en', self::enemies(), [
            'name', 'rank', 'movement', 'hp', 'contact_damage', 'move_speed', 'ability_id', 'score',
        ]);
        $this->insertStages();
        $this->insertStageEnemies();
    }

    /**
     * @param  list<list<mixed>>  $rows  列の順番は $columns と同じ
     * @param  list<string>  $columns
     */
    private function insert(string $tableKey, string $prefix, array $rows, array $columns): void
    {
        $records = [];
        foreach ($rows as $index => $row) {
            $records[sprintf('%s_%03d', $prefix, $index + 1)] = array_combine($columns, $row);
        }
        $this->save($tableKey, $records);
    }

    /**
     * @param  array<string, array<string, mixed>>  $records  record_key => data
     */
    private function save(string $tableKey, array $records): void
    {
        $tableId = MasterTable::where('key', $tableKey)->value('id');

        foreach ($records as $recordKey => $data) {
            MasterRecord::create([
                'master_table_id' => $tableId,
                'record_key' => $recordKey,
                'data' => $data,
                'updated_by' => $this->admin->id,
                'release_id' => $this->release->id,
            ]);
        }
    }

    private function insertStages(): void
    {
        $records = [];
        $previousKey = null;
        foreach (self::stages() as [$world, $stageNo, $name, $width, $height, $timeLimit, $difficulty]) {
            // record_key は st_{ワールド}_{ステージ番号}。各ステージは1つ前のステージのクリアで解放される
            $key = "st_{$world}_{$stageNo}";
            $records[$key] = [
                'name' => $name,
                'world' => $world,
                'stage_no' => $stageNo,
                'width' => $width,
                'height' => $height,
                'time_limit_sec' => $timeLimit,
                'difficulty' => $difficulty,
                'unlock_stage_id' => $previousKey,
            ];
            $previousKey = $key;
        }
        $this->save('stages', $records);
    }

    /**
     * 敵配置を生成する。ワールドごとに出現する通常敵を変え、
     * 各ワールドの3面目に中ボス、4面目にボスを1体ずつ置く（ボスは1ステージ1体まで）。
     */
    private function insertStageEnemies(): void
    {
        $random = new Randomizer(new Mt19937(self::RANDOM_SEED));

        $enemies = [];
        foreach (self::enemies() as $index => $enemy) {
            $enemies[sprintf('en_%03d', $index + 1)] = $enemy;
        }

        $records = [];
        foreach (self::stages() as [$world, $stageNo, , $width, $height]) {
            $stageKey = "st_{$world}_{$stageNo}";
            $pool = self::NORMAL_ENEMIES_BY_WORLD[$world];

            // 3・4面は中ボス・ボスの1枠を除いた数だけ通常敵を置く
            $normalCount = self::PLACEMENTS_PER_STAGE[$stageKey] - ($stageNo >= 3 ? 1 : 0);
            $placements = [];
            for ($i = 0; $i < $normalCount; $i++) {
                $placements[] = $pool[$random->getInt(0, count($pool) - 1)];
            }

            if ($stageNo === 3) {
                $placements[] = self::MID_BOSS_BY_WORLD[$world];
            } elseif ($stageNo === 4) {
                $placements[] = self::BOSS_BY_WORLD[$world];
            }

            foreach ($placements as $enemyKey) {
                [, $rank, $movement] = $enemies[$enemyKey];
                $isNormal = $rank === 'normal';

                $records[sprintf('se_%04d', count($records) + 1)] = [
                    'stage_id' => $stageKey,
                    'enemy_id' => $enemyKey,
                    // ボス・中ボスはステージの奥、通常敵はスタート地点付近を避けて散らす
                    'pos_x' => $isNormal ? $random->getInt(3000, ($width - 200) * 10) / 10 : (float) ($width - 400),
                    // 飛ぶ敵は空中、それ以外は地面（高さの下から 64 の位置）に置く
                    'pos_y' => $movement === 'fly' ? $random->getInt(1000, ($height - 200) * 10) / 10 : (float) ($height - 64),
                    'spawn_frame' => $isNormal ? $random->getInt(0, 20) * 60 : 0,
                    'respawn' => $isNormal && $random->getInt(1, 10) <= 3,
                ];
            }
        }
        $this->save('stage_enemies', $records);
    }

    // ステージごとの配置数（合計150）
    private const PLACEMENTS_PER_STAGE = [
        'st_1_1' => 10, 'st_1_2' => 11, 'st_1_3' => 12, 'st_1_4' => 13,
        'st_2_1' => 12, 'st_2_2' => 12, 'st_2_3' => 13, 'st_2_4' => 14,
        'st_3_1' => 12, 'st_3_2' => 13, 'st_3_3' => 14, 'st_3_4' => 14,
    ];

    // ワールドごとに出現する通常敵（ワールドが進むほど強い敵になる）
    private const NORMAL_ENEMIES_BY_WORLD = [
        1 => ['en_001', 'en_002', 'en_003', 'en_004', 'en_005', 'en_006', 'en_007', 'en_008', 'en_009'],
        2 => ['en_007', 'en_010', 'en_011', 'en_014', 'en_016', 'en_017', 'en_019', 'en_021'],
        3 => ['en_012', 'en_013', 'en_015', 'en_017', 'en_018', 'en_019', 'en_020', 'en_021'],
    ];

    private const MID_BOSS_BY_WORLD = [1 => 'en_023', 2 => 'en_024', 3 => 'en_025'];

    private const BOSS_BY_WORLD = [1 => 'en_028', 2 => 'en_029', 3 => 'en_030'];

    /**
     * 名前, 攻撃タイプ, 属性, 威力, 射程, 発生フレーム, 硬直フレーム
     *
     * @return list<list<mixed>>
     */
    private static function abilities(): array
    {
        return [
            ['体当たり', 'melee', 'none', 10, 1.0, 6, 30],
            ['ひっかき', 'melee', 'none', 12, 1.2, 5, 24],
            ['回転キック', 'melee', 'wind', 25, 2.0, 12, 45],
            ['火花パンチ', 'melee', 'fire', 30, 1.5, 10, 40],
            ['氷柱突き', 'melee', 'ice', 35, 2.5, 14, 50],
            ['雷撃ハンマー', 'melee', 'thunder', 60, 2.0, 30, 90],
            ['タネ飛ばし', 'projectile', 'none', 8, 12.0, 8, 40],
            ['火の玉', 'projectile', 'fire', 20, 15.0, 15, 60],
            ['つらら撃ち', 'projectile', 'ice', 22, 18.0, 18, 60],
            ['稲妻ショット', 'projectile', 'thunder', 28, 20.0, 20, 75],
            ['かまいたち', 'projectile', 'wind', 18, 16.0, 10, 45],
            ['三連バブル', 'projectile', 'none', 12, 10.0, 12, 50],
            ['地響き', 'area', 'none', 40, 6.0, 40, 120],
            ['火柱', 'area', 'fire', 55, 4.0, 36, 150],
            ['吹雪', 'area', 'ice', 45, 8.0, 45, 180],
            ['落雷陣', 'area', 'thunder', 70, 7.0, 50, 200],
            ['竜巻', 'area', 'wind', 50, 9.0, 42, 160],
            ['殻にこもる', 'guard', 'none', 0, 0.0, 4, 90],
            ['炎の盾', 'guard', 'fire', 5, 1.0, 6, 120],
            ['嵐の障壁', 'guard', 'wind', 0, 3.0, 8, 150],
        ];
    }

    /**
     * 名前, ランク, 移動, HP, 接触ダメージ, 移動速度, 攻撃パターン, スコア
     *
     * @return list<list<mixed>>
     */
    private static function enemies(): array
    {
        return [
            ['モコモコ', 'normal', 'walk', 3, 1, 1.0, null, 100],
            ['ピョンタ', 'normal', 'jump', 4, 1, 1.5, null, 100],
            ['フワリン', 'normal', 'fly', 3, 1, 1.2, null, 120],
            ['トゲッコ', 'normal', 'walk', 5, 2, 0.8, 'ab_001', 150],
            ['カラカラ', 'normal', 'walk', 4, 1, 2.0, 'ab_002', 150],
            ['ツツキドリ', 'normal', 'fly', 5, 2, 2.5, 'ab_002', 200],
            ['タネボウ', 'normal', 'stationary', 6, 1, 0.0, 'ab_007', 200],
            ['ヒバチ', 'normal', 'walk', 6, 2, 1.0, 'ab_004', 250],
            ['ホノコウモリ', 'normal', 'fly', 5, 2, 3.0, 'ab_008', 250],
            ['ユキダマン', 'normal', 'walk', 8, 2, 0.8, 'ab_009', 300],
            ['コオリガニ', 'normal', 'walk', 10, 2, 1.2, 'ab_005', 300],
            ['ピリリ', 'normal', 'fly', 6, 3, 3.5, 'ab_010', 350],
            ['カミナリダケ', 'normal', 'stationary', 8, 2, 0.0, 'ab_010', 350],
            ['カゼノコ', 'normal', 'jump', 6, 2, 2.2, 'ab_011', 300],
            ['ツムジ', 'normal', 'fly', 7, 2, 4.0, 'ab_011', 350],
            ['アワフキ', 'normal', 'stationary', 9, 1, 0.0, 'ab_012', 300],
            ['カクレガメ', 'normal', 'walk', 15, 3, 0.5, 'ab_018', 400],
            ['イワゴロン', 'normal', 'walk', 20, 4, 1.8, 'ab_003', 450],
            ['ハネウサ', 'normal', 'jump', 8, 2, 2.8, 'ab_003', 300],
            ['ヨロイムシ', 'normal', 'walk', 18, 3, 0.7, 'ab_019', 450],
            ['ツララコウモリ', 'normal', 'fly', 9, 3, 3.2, 'ab_009', 400],
            ['ゴロンダイ', 'mid_boss', 'walk', 120, 5, 1.0, 'ab_013', 2000],
            ['ヒノコドリ', 'mid_boss', 'fly', 100, 5, 3.5, 'ab_014', 2200],
            ['ヒョウガノヨロイ', 'mid_boss', 'walk', 150, 6, 0.8, 'ab_015', 2500],
            ['イカヅチボウズ', 'mid_boss', 'jump', 130, 6, 2.0, 'ab_016', 2500],
            ['センプウオオワシ', 'mid_boss', 'fly', 140, 6, 4.5, 'ab_017', 2800],
            ['ガンセキガメ', 'mid_boss', 'stationary', 200, 4, 0.0, 'ab_020', 2600],
            ['業火の番人ヴォルカ', 'boss', 'walk', 800, 8, 1.5, 'ab_014', 10000],
            ['氷晶の女王セリシア', 'boss', 'fly', 900, 8, 2.5, 'ab_015', 12000],
            ['雷雲の大将ゴウライ', 'boss', 'jump', 1200, 10, 2.0, 'ab_016', 15000],
        ];
    }

    /**
     * ワールド, ステージ番号, 名前, 幅, 高さ, 制限時間（0は無制限）, 難易度
     *
     * @return list<list<mixed>>
     */
    private static function stages(): array
    {
        return [
            [1, 1, 'はじまりの草原', 3200, 720, 0, 1],
            [1, 2, 'そよ風の丘', 3600, 720, 300, 1],
            [1, 3, '赤土の洞くつ', 4000, 900, 300, 2],
            [1, 4, '火山のふもと', 4800, 1080, 400, 2],
            [2, 1, '雪どけの小道', 4000, 720, 300, 2],
            [2, 2, 'こおりの湖', 4400, 900, 300, 3],
            [2, 3, '吹雪の峠', 5200, 1080, 400, 3],
            [2, 4, '氷晶の城', 5600, 1440, 500, 4],
            [3, 1, '雨雲の谷', 4800, 900, 300, 3],
            [3, 2, '雷鳴の塔', 3200, 2160, 400, 4],
            [3, 3, '嵐の空中回廊', 6000, 1080, 400, 4],
            [3, 4, '雷雲の玉座', 6400, 1440, 600, 5],
        ];
    }
}
