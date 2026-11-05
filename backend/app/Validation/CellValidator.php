<?php

namespace App\Validation;

use App\Enums\ColumnType;
use App\Models\MasterColumn;

/**
 * 単一セルの検証（docs/SPEC.md 5章）：型、必須、数値範囲、文字数、列挙値。
 * フロントの frontend/src/lib/validation.ts と同じルール・同じメッセージで、サーバー側の最終判定に使う。
 * ルールやメッセージを変えるときは両方を直し、両方のテストにある共通の例も合わせて直す。
 *
 * 参照先が存在するかどうかは、他のレコードを見ないと分からないため、ここでは扱わない（横断検証、第5週）。
 */
class CellValidator
{
    /** エラーがあればメッセージを、なければ null を返す */
    public function validate(MasterColumn $column, mixed $value): ?string
    {
        // 空欄は null と '' のどちらでも表せるので、同じものとして扱う
        if ($value === null || $value === '') {
            return $column->is_required ? '必須です' : null;
        }

        return match ($column->type) {
            // JSON の 1.0 は PHP では float になるが、JavaScript では 1 と区別できないため、整数とみなす
            ColumnType::Int => is_int($value) || (is_float($value) && floor($value) === $value)
                ? $this->checkRange($column, $value)
                : '整数で入力してください',
            ColumnType::Float => is_int($value) || (is_float($value) && is_finite($value))
                ? $this->checkRange($column, $value)
                : '数値で入力してください',
            ColumnType::String => match (true) {
                ! is_string($value) => '文字列で入力してください',
                $column->max_length !== null && mb_strlen($value) > $column->max_length => "{$column->max_length}文字以内で入力してください",
                default => null,
            },
            ColumnType::Bool => is_bool($value) ? null : 'はい・いいえで指定してください',
            ColumnType::Enum => is_string($value) && in_array($value, $column->enum_values ?? [], true)
                ? null
                : '選択肢にない値です',
            ColumnType::Ref => is_string($value) ? null : '参照先の record_key を指定してください',
        };
    }

    /**
     * 1行分の検証結果。エラーのある列だけを、列の key => メッセージで返す
     *
     * @param  iterable<MasterColumn>  $columns
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    public function validateRecord(iterable $columns, array $data): array
    {
        $errors = [];
        foreach ($columns as $column) {
            $error = $this->validate($column, $data[$column->key] ?? null);
            if ($error !== null) {
                $errors[$column->key] = $error;
            }
        }

        return $errors;
    }

    private function checkRange(MasterColumn $column, int|float $value): ?string
    {
        $min = $column->min_value;
        $max = $column->max_value;

        if ($min !== null && $max !== null) {
            return $value < $min || $value > $max
                ? "{$this->format($min)}〜{$this->format($max)} の範囲で入力してください"
                : null;
        }
        if ($min !== null && $value < $min) {
            return "{$this->format($min)} 以上で入力してください";
        }
        if ($max !== null && $value > $max) {
            return "{$this->format($max)} 以下で入力してください";
        }

        return null;
    }

    /** 範囲は DECIMAL 列から float で読むため、9999.0 を JavaScript と同じく "9999" と表示する */
    private function format(float $number): string
    {
        return (string) (floor($number) === $number ? (int) $number : $number);
    }
}
