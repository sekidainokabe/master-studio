<?php

namespace Database\Seeders;

use App\Enums\ColumnType;
use App\Models\MasterTable;
use Illuminate\Database\Seeder;

/**
 * 4つのマスタと列定義（docs/SPEC.md 4章）。
 * スキーマ定義の編集画面は作らないため、マスタの構造はこのシーダーで管理する。
 */
class MasterSchemaSeeder extends Seeder
{
    public function run(): void
    {
        $tables = [];
        foreach (self::definitions() as $key => $definition) {
            $tables[$key] = MasterTable::create([
                'key' => $key,
                'name' => $definition['name'],
                'description' => $definition['description'],
            ]);
        }

        // ref 列は参照先マスタの id が必要なので、全マスタを作ってから列を作る（stages は自己参照もある）
        foreach (self::definitions() as $key => $definition) {
            foreach ($definition['columns'] as $index => $column) {
                $ref = $column['ref'] ?? null;
                unset($column['ref']);

                $tables[$key]->columns()->create([
                    ...$column,
                    'ref_master_table_id' => $ref !== null ? $tables[$ref]->id : null,
                    'sort_order' => $index + 1,
                ]);
            }
        }
    }

    /**
     * @return array<string, array{name: string, description: string, columns: list<array<string, mixed>>}>
     */
    private static function definitions(): array
    {
        return [
            'abilities' => [
                'name' => '攻撃パターン',
                'description' => '敵が使う攻撃の種類と性能',
                'columns' => [
                    self::string('name', '名前', maxLength: 30),
                    self::enum('attack_type', '攻撃タイプ', ['melee', 'projectile', 'area', 'guard']),
                    self::enum('element', '属性', ['none', 'fire', 'ice', 'thunder', 'wind']),
                    self::number(ColumnType::Int, 'power', '威力', 0, 999),
                    self::number(ColumnType::Float, 'range', '射程', 0, 50),
                    self::number(ColumnType::Int, 'startup_frames', '発生フレーム', 0, 600),
                    self::number(ColumnType::Int, 'cooldown_frames', '硬直フレーム', 0, 600),
                ],
            ],
            'enemies' => [
                'name' => '敵',
                'description' => '敵キャラクターの能力値',
                'columns' => [
                    self::string('name', '名前', maxLength: 30),
                    self::enum('rank', 'ランク', ['normal', 'mid_boss', 'boss']),
                    self::enum('movement', '移動', ['walk', 'fly', 'jump', 'stationary']),
                    self::number(ColumnType::Int, 'hp', 'HP', 1, 9999),
                    self::number(ColumnType::Int, 'contact_damage', '接触ダメージ', 0, 99),
                    self::number(ColumnType::Float, 'move_speed', '移動速度', 0, 20),
                    self::ref('ability_id', '攻撃パターン', 'abilities', required: false),
                    self::number(ColumnType::Int, 'score', 'スコア', 0, 99999),
                ],
            ],
            'stages' => [
                'name' => 'ステージ',
                'description' => 'ステージの広さ・制限時間・解放条件',
                'columns' => [
                    self::string('name', '名前', maxLength: 30),
                    self::number(ColumnType::Int, 'world', 'ワールド', 1, 9),
                    self::number(ColumnType::Int, 'stage_no', 'ステージ番号', 1, 20),
                    self::number(ColumnType::Int, 'width', '幅', 640, 20000),
                    self::number(ColumnType::Int, 'height', '高さ', 360, 4000),
                    self::number(ColumnType::Int, 'time_limit_sec', '制限時間（秒）', 0, 999),
                    self::number(ColumnType::Int, 'difficulty', '難易度', 1, 5),
                    self::ref('unlock_stage_id', '解放条件ステージ', 'stages', required: false),
                ],
            ],
            'stage_enemies' => [
                'name' => '敵配置',
                'description' => 'ステージ上の敵の配置と出現タイミング',
                'columns' => [
                    self::ref('stage_id', 'ステージ', 'stages'),
                    self::ref('enemy_id', '敵', 'enemies'),
                    // 上限はステージの width / height に依存するため、横断検証で確かめる
                    self::number(ColumnType::Float, 'pos_x', 'X座標', 0, null),
                    self::number(ColumnType::Float, 'pos_y', 'Y座標', 0, null),
                    self::number(ColumnType::Int, 'spawn_frame', '出現フレーム', 0, null),
                    ['key' => 'respawn', 'name' => '再出現', 'type' => ColumnType::Bool, 'is_required' => true],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function string(string $key, string $name, int $maxLength): array
    {
        return ['key' => $key, 'name' => $name, 'type' => ColumnType::String, 'is_required' => true, 'max_length' => $maxLength];
    }

    /**
     * @param  list<string>  $values
     * @return array<string, mixed>
     */
    private static function enum(string $key, string $name, array $values): array
    {
        return ['key' => $key, 'name' => $name, 'type' => ColumnType::Enum, 'is_required' => true, 'enum_values' => $values];
    }

    /** @return array<string, mixed> */
    private static function number(ColumnType $type, string $key, string $name, ?float $min, ?float $max): array
    {
        return ['key' => $key, 'name' => $name, 'type' => $type, 'is_required' => true, 'min_value' => $min, 'max_value' => $max];
    }

    /** @return array<string, mixed> */
    private static function ref(string $key, string $name, string $refTable, bool $required = true): array
    {
        return ['key' => $key, 'name' => $name, 'type' => ColumnType::Ref, 'is_required' => $required, 'ref' => $refTable];
    }
}
