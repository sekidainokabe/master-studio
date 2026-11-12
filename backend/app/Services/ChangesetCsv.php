<?php

namespace App\Services;

use App\Enums\ColumnType;
use App\Models\Changeset;
use App\Models\MasterColumn;
use App\Models\MasterTable;
use Illuminate\Validation\ValidationException;

/**
 * 変更セットの中の1つのマスタを、CSV で取り込む・書き出す（docs/plan-week5.md）。
 *
 * 取り込みは「追加と更新だけ」。CSV にない行は消さない（書き漏れで行が消える事故を防ぐため。削除は画面で行う）。
 * 型が合わない値はそのまま取り込み、画面でエラーとして表示する（第4週の「エラーがあっても保存できる」と同じ）。
 * 見出しが読めない、record_key の形式が違うなど、データとして扱えないものは 422 で全体を拒否し、何も保存しない。
 */
class ChangesetCsv
{
    public const MAX_ROWS = 500;

    /** 422 のときに並べるエラーの数（多すぎると読めないため） */
    private const MAX_MESSAGES = 20;

    private const BOM = "\xEF\xBB\xBF";

    public function __construct(private readonly ChangesetRecords $records) {}

    /**
     * @return array{created: int, updated: int, unchanged: int}
     *
     * @throws ValidationException
     */
    public function import(Changeset $changeset, MasterTable $table, string $content): array
    {
        $table->loadMissing(['columns', 'records']);
        [$header, $rows] = $this->parse($this->decode($content));
        $columns = $this->columnsFor($table, $header);

        if (count($rows) > self::MAX_ROWS) {
            $this->fail(['行が多すぎます（'.count($rows).' 行）。1回に取り込めるのは '.self::MAX_ROWS.' 行までです。']);
        }

        // 今の編集用データ（本番に変更セットの変更を重ねたもの）。CSV にない列は、この値のままにする
        $current = collect($this->records->rows($changeset, $table))->keyBy('record_key');

        $errors = [];
        $seen = [];
        $records = [];
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

        foreach ($rows as $line => $fields) {
            if (count($fields) !== count($header)) {
                $errors[] = "{$line} 行目：列の数（".count($fields).'）が見出し（'.count($header).'）と合いません。';

                continue;
            }
            $values = array_combine($header, $fields);
            $key = trim($values['record_key']);

            $keyError = match (true) {
                $key === '' => 'record_key がありません。',
                mb_strlen($key) > 50 => 'record_key は50文字以内で入力してください。',
                preg_match('/^[a-z0-9_]+$/', $key) !== 1 => "record_key は半角の英小文字・数字・_ で入力してください：{$key}",
                isset($seen[$key]) => "record_key が {$seen[$key]} 行目と重複しています：{$key}",
                default => null,
            };
            if ($keyError !== null) {
                $errors[] = "{$line} 行目：{$keyError}";

                continue;
            }
            $seen[$key] = $line;

            $data = [];
            foreach ($columns as $column) {
                $data[$column->key] = $this->convert($column, $values[$column->key]);
            }

            $existing = $current->get($key);
            if ($existing === null) {
                $counts['created']++;
                $records[] = ['record_key' => $key, 'data' => $data];

                continue;
            }
            $merged = [...$existing['data'], ...$data];
            // 削除する予定の行が CSV にあれば、削除をやめて CSV の値にする
            if ($existing['status'] !== 'deleted' && $this->records->sameData($table->columns, $merged, $existing['data'])) {
                $counts['unchanged']++;
            } else {
                $counts['updated']++;
                $records[] = ['record_key' => $key, 'data' => $merged];
            }
        }

        if ($errors !== []) {
            $this->fail($errors);
        }
        if ($records !== []) {
            $this->records->save($changeset, $table, $records);
        }

        return $counts;
    }

    /**
     * 編集用データ（削除する行を除く）を CSV にする。
     * Excel でそのまま開けるよう、BOM 付きの UTF-8・改行は CRLF にする
     */
    public function export(Changeset $changeset, MasterTable $table): string
    {
        $table->loadMissing(['columns', 'records']);
        $columns = $table->columns;

        $out = fopen('php://temp', 'r+');
        fwrite($out, self::BOM);
        fputcsv($out, ['record_key', ...$columns->pluck('key')], ',', '"', '', "\r\n");

        foreach ($this->records->rows($changeset, $table) as $row) {
            if ($row['status'] === 'deleted') {
                continue;
            }
            $fields = [$row['record_key']];
            foreach ($columns as $column) {
                $fields[] = $this->format($row['data'][$column->key] ?? null);
            }
            fputcsv($out, $fields, ',', '"', '', "\r\n");
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /**
     * 文字コードを UTF-8 にそろえる。UTF-8（BOM あり・なし）と Shift_JIS（日本語版 Excel の「CSV」保存）を受け付ける
     */
    private function decode(string $content): string
    {
        if (str_contains($content, "\0")) {
            $this->fail(['CSV ファイルではないようです。Excel などで「CSV」形式で保存したファイルを選んでください。']);
        }
        if (str_starts_with($content, self::BOM)) {
            $content = substr($content, strlen(self::BOM));
        }
        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }
        // SJIS-win は Windows の Shift_JIS（①や髙などの機種依存文字を含む）
        if (mb_check_encoding($content, 'SJIS-win')) {
            return mb_convert_encoding($content, 'UTF-8', 'SJIS-win');
        }
        $this->fail(['文字コードを読み取れません。UTF-8 か Shift_JIS で保存してください。']);
    }

    /**
     * 見出しとデータの行に分ける。空の行は飛ばす。
     * データの行は「CSV の何行目か」（見出しが1行目）をキーにして、エラーの場所を示せるようにする
     *
     * @return array{0: list<string>, 1: array<int, list<string>>}
     */
    private function parse(string $text): array
    {
        $in = fopen('php://temp', 'r+');
        fwrite($in, $text);
        rewind($in);

        $header = null;
        $rows = [];
        $line = 0;
        // escape に '' を渡し、"" だけを引用符のエスケープとして扱う（RFC 4180・Excel と同じ）
        while (($fields = fgetcsv($in, null, ',', '"', '')) !== false) {
            $line++;
            if ($fields === [null] || $fields === ['']) {
                continue;
            }
            if ($header === null) {
                $header = array_map(fn ($f) => trim((string) $f), $fields);
            } else {
                $rows[$line] = array_map(fn ($f) => (string) $f, $fields);
            }
        }
        fclose($in);

        if ($header === null) {
            $this->fail(['CSV が空です。1行目に見出し（record_key と列の key）を入れてください。']);
        }

        return [$header, $rows];
    }

    /**
     * 見出しを確かめ、取り込む列（record_key 以外）を見出しの順で返す。一部の列だけでもよい
     *
     * @param  list<string>  $header
     * @return list<MasterColumn>
     */
    private function columnsFor(MasterTable $table, array $header): array
    {
        $columns = $table->columns->keyBy('key');

        $errors = [];
        if (! in_array('record_key', $header, true)) {
            $errors[] = '見出しに record_key がありません。';
        }
        $duplicates = array_keys(array_filter(array_count_values($header), fn (int $n) => $n > 1));
        if ($duplicates !== []) {
            $errors[] = '見出しが重複しています：'.implode('、', $duplicates);
        }
        $unknown = array_values(array_filter($header, fn (string $h) => $h !== 'record_key' && ! $columns->has($h)));
        if ($unknown !== []) {
            $errors[] = '存在しない列です：'.implode('、', $unknown).'（使える列：'.$columns->keys()->implode('、').'）';
        }
        if ($errors !== []) {
            $this->fail($errors);
        }

        return array_values(array_map(fn (string $h) => $columns[$h], array_filter($header, fn (string $h) => $h !== 'record_key')));
    }

    /**
     * CSV の文字列を、列の型に合わせた値にする。変換できない値は文字列のまま返し、検証でエラーにする
     */
    private function convert(MasterColumn $column, string $raw): mixed
    {
        // 文字列の列は、前後の空白も値の一部として残す
        $value = $column->type === ColumnType::String ? $raw : trim($raw);
        if ($value === '') {
            return null;
        }

        return match ($column->type) {
            // 画面の入力（frontend/src/lib/editDraft.ts の parseCellInput）と同じ判定
            ColumnType::Int, ColumnType::Float => preg_match('/^-?\d+(\.\d+)?$/', $value) === 1
                ? (str_contains($value, '.') ? (float) $value : (int) $value)
                : $value,
            ColumnType::Bool => match (mb_strtolower($value)) {
                'true', '1', 'はい' => true,
                'false', '0', 'いいえ' => false,
                default => $value,
            },
            default => $value,
        };
    }

    /** CSV のセルの文字列。真偽値は Excel が真偽値として扱う TRUE / FALSE にする */
    private function format(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            default => (string) $value,
        };
    }

    /**
     * @param  list<string>  $messages
     */
    private function fail(array $messages): never
    {
        $shown = array_slice($messages, 0, self::MAX_MESSAGES);
        if (count($messages) > self::MAX_MESSAGES) {
            $shown[] = 'ほか '.(count($messages) - self::MAX_MESSAGES).' 件';
        }

        throw ValidationException::withMessages(['file' => $shown]);
    }
}
