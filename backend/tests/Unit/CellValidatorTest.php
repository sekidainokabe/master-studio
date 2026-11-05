<?php

namespace Tests\Unit;

use App\Models\MasterColumn;
use App\Validation\CellValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CellValidatorTest extends TestCase
{
    /**
     * フロントの src/lib/validation.test.ts と同じ列。結果がずれないよう、変えるときは両方を直す
     *
     * @return array<string, MasterColumn>
     */
    private static function columns(): array
    {
        $column = fn (array $attributes) => new MasterColumn([
            'is_required' => false, 'min_value' => null, 'max_value' => null,
            'max_length' => null, 'enum_values' => null, ...$attributes,
        ]);

        return [
            'name' => $column(['key' => 'name', 'type' => 'string', 'is_required' => true, 'max_length' => 5]),
            'hp' => $column(['key' => 'hp', 'type' => 'int', 'is_required' => true, 'min_value' => 1, 'max_value' => 9999]),
            'spawn_frame' => $column(['key' => 'spawn_frame', 'type' => 'int', 'min_value' => 0]),
            'speed' => $column(['key' => 'speed', 'type' => 'float', 'min_value' => 0, 'max_value' => 20]),
            'respawn' => $column(['key' => 'respawn', 'type' => 'bool']),
            'rank' => $column(['key' => 'rank', 'type' => 'enum', 'is_required' => true, 'enum_values' => ['normal', 'boss']]),
            'ability_id' => $column(['key' => 'ability_id', 'type' => 'ref']),
        ];
    }

    /**
     * フロントと同じ例（最後の2件は PHP の JSON 変換に特有のもの）
     *
     * @return list<array{string, mixed, ?string}>
     */
    public static function cases(): array
    {
        return [
            // 必須と空欄
            ['name', null, '必須です'],
            ['name', '', '必須です'],
            ['spawn_frame', null, null],
            ['ability_id', '', null],
            // string
            ['name', 'スライム', null],
            ['name', 'あいうえお', null],
            ['name', 'あいうえおか', '5文字以内で入力してください'],
            ['name', '🐉🐉🐉🐉🐉', null],
            ['name', 123, '文字列で入力してください'],
            // int
            ['hp', 1, null],
            ['hp', 9999, null],
            ['hp', 0, '1〜9999 の範囲で入力してください'],
            ['hp', 10000, '1〜9999 の範囲で入力してください'],
            ['hp', 1.5, '整数で入力してください'],
            ['hp', '100', '整数で入力してください'],
            ['spawn_frame', 0, null],
            ['spawn_frame', -1, '0 以上で入力してください'],
            // float
            ['speed', 1, null],
            ['speed', 2.5, null],
            ['speed', 20.5, '0〜20 の範囲で入力してください'],
            ['speed', true, '数値で入力してください'],
            // bool
            ['respawn', true, null],
            ['respawn', false, null],
            ['respawn', 'true', 'はい・いいえで指定してください'],
            // enum
            ['rank', 'boss', null],
            ['rank', 'Boss', '選択肢にない値です'],
            // ref（存在するかどうかは横断検証で見る）
            ['ability_id', 'ab_001', null],
            ['ability_id', 1, '参照先の record_key を指定してください'],
            // JSON の 100.0 は PHP では float になるが、整数として扱う
            ['hp', 100.0, null],
            ['speed', 0.0, null],
        ];
    }

    #[DataProvider('cases')]
    public function test_validate(string $key, mixed $value, ?string $expected): void
    {
        $this->assertSame($expected, (new CellValidator)->validate(self::columns()[$key], $value));
    }

    public function test_validate_record_returns_only_columns_with_errors(): void
    {
        $errors = (new CellValidator)->validateRecord(self::columns(), [
            'name' => 'スライム',
            'hp' => 0,
            'speed' => 1.5,
            'rank' => 'king',
        ]);

        $this->assertSame([
            'hp' => '1〜9999 の範囲で入力してください',
            'rank' => '選択肢にない値です',
        ], $errors);
    }
}
