<?php

namespace Tests\Feature;

use App\Enums\ChangesetStatus;
use App\Enums\UserRole;
use App\Models\Changeset;
use App\Models\ChangesetItem;
use App\Models\MasterRecord;
use App\Models\MasterTable;
use App\Models\User;
use App\Services\ChangesetRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * CSV の取り込み（POST .../import）と書き出し（GET .../export）
 */
class ChangesetCsvApiTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;

    private Changeset $changeset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->editor = User::where('role', UserRole::Editor)->firstOrFail();
        $this->changeset = Changeset::factory()->for($this->editor, 'author')->create();
    }

    private function import(string $content, string $master = 'abilities', ?User $as = null, string $filename = 'data.csv'): TestResponse
    {
        return $this->actingAs($as ?? $this->editor, 'web')->post(
            "/api/changesets/{$this->changeset->id}/masters/{$master}/import",
            ['file' => UploadedFile::fake()->createWithContent($filename, $content)],
            ['Accept' => 'application/json'],
        );
    }

    private function export(string $master = 'abilities', ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->editor, 'web')
            ->get("/api/changesets/{$this->changeset->id}/masters/{$master}/export");
    }

    /** 編集用データの1行（record_key => 行） */
    private function row(string $master, string $key): ?array
    {
        $table = MasterTable::where('key', $master)->firstOrFail()->load(['columns', 'records']);

        return collect(app(ChangesetRecords::class)->rows($this->changeset, $table))->keyBy('record_key')->get($key);
    }

    private function production(string $master, string $key): array
    {
        return MasterRecord::where('master_table_id', MasterTable::where('key', $master)->value('id'))
            ->where('record_key', $key)->firstOrFail()->data;
    }

    public function test_imports_partial_columns_as_create_and_update(): void
    {
        $before = $this->production('abilities', 'ab_001');

        $this->import("record_key,power\nab_001,123\nab_901,5\n")
            ->assertOk()
            ->assertExactJson(['data' => ['created' => 1, 'updated' => 1, 'unchanged' => 0]]);

        $ab001 = $this->row('abilities', 'ab_001');
        $this->assertSame('updated', $ab001['status']);
        $this->assertSame(123, $ab001['data']['power']);
        // CSV にない列は今の値のまま
        $this->assertSame($before['name'], $ab001['data']['name']);

        $ab901 = $this->row('abilities', 'ab_901');
        $this->assertSame('created', $ab901['status']);
        $this->assertSame(5, $ab901['data']['power']);
        // 足りない値も取り込み、エラーとして返す
        $this->assertSame('必須です', ((array) $ab901['errors'])['name']);
    }

    public function test_rows_not_in_csv_are_not_deleted(): void
    {
        $this->import("record_key,power\nab_001,123\n")->assertOk();

        $this->assertSame(['ab_001'], ChangesetItem::pluck('record_key')->all());
    }

    public function test_reads_utf8_with_bom_crlf_and_quoted_values(): void
    {
        $this->import("\xEF\xBB\xBFrecord_key,name\r\nab_001,\"体当たり, \"\"改\"\"\"\r\n")->assertOk();

        $this->assertSame('体当たり, "改"', $this->row('abilities', 'ab_001')['data']['name']);
    }

    public function test_reads_shift_jis_saved_by_excel(): void
    {
        $sjis = mb_convert_encoding("record_key,name\r\nab_001,髙い壁①\r\n", 'SJIS-win', 'UTF-8');
        $this->assertFalse(mb_check_encoding($sjis, 'UTF-8'));

        $this->import($sjis)->assertOk();

        $this->assertSame('髙い壁①', $this->row('abilities', 'ab_001')['data']['name']);
    }

    public function test_converts_values_by_column_type(): void
    {
        $csv = implode("\n", [
            'record_key,pos_x,spawn_frame,respawn',
            'se_0001,12.5,60,はい',
            'se_0002,100, ,FALSE',
            'se_0003,1,0,1',
            'se_0004,abc,1.5,たぶん',
        ]);

        $this->import($csv, 'stage_enemies')->assertOk();

        $this->assertSame([12.5, 60, true], $this->values('se_0001'));
        $this->assertSame([100, null, false], $this->values('se_0002'));
        $this->assertSame([1, 0, true], $this->values('se_0003'));
        // 変換できない値は文字列のまま保存し、エラーとして返す
        $se0004 = $this->row('stage_enemies', 'se_0004');
        $this->assertSame(['abc', 1.5, 'たぶん'], $this->values('se_0004'));
        $this->assertSame(
            ['pos_x' => '数値で入力してください', 'spawn_frame' => '整数で入力してください', 'respawn' => 'はい・いいえで指定してください'],
            (array) $se0004['errors'],
        );
    }

    /** @return list<mixed> pos_x, spawn_frame, respawn */
    private function values(string $key): array
    {
        $data = $this->row('stage_enemies', $key)['data'];

        return [$data['pos_x'], $data['spawn_frame'], $data['respawn']];
    }

    public function test_same_values_are_counted_as_unchanged(): void
    {
        $name = $this->production('abilities', 'ab_001')['name'];

        $this->import("record_key,name\nab_001,{$name}\n")
            ->assertOk()
            ->assertExactJson(['data' => ['created' => 0, 'updated' => 0, 'unchanged' => 1]]);
        $this->assertSame(0, ChangesetItem::count());
    }

    public function test_row_to_be_deleted_is_restored_by_csv(): void
    {
        app(ChangesetRecords::class)->save($this->changeset, MasterTable::where('key', 'abilities')->firstOrFail(), [
            ['record_key' => 'ab_002', 'data' => null],
        ]);
        $name = $this->production('abilities', 'ab_002')['name'];

        $this->import("record_key,name\nab_002,{$name}\n")
            ->assertOk()
            ->assertJsonPath('data.updated', 1);

        // 本番と同じ値に戻ったので、変更の記録ごと消える
        $this->assertSame('unchanged', $this->row('abilities', 'ab_002')['status']);
    }

    public function test_rejects_csv_that_cannot_be_read_and_saves_nothing(): void
    {
        $cases = [
            "record_key,power,unknown\nab_001,1,2\n" => '存在しない列です：unknown（使える列：name、attack_type、',
            "name,power\n体当たり,1\n" => '見出しに record_key がありません。',
            "record_key,power,power\nab_001,1,2\n" => '見出しが重複しています：power',
            "record_key,power\nab_001,1\nAB-1,2\n" => '3 行目：record_key は半角の英小文字・数字・_ で入力してください：AB-1',
            "record_key,power\nab_001,1\nab_001,2\n" => '3 行目：record_key が 2 行目と重複しています：ab_001',
            "record_key,power\nab_001,1,9\n" => '2 行目：列の数（3）が見出し（2）と合いません。',
            "record_key,power\n,1\n" => '2 行目：record_key がありません。',
            '' => 'CSV が空です。1行目に見出し（record_key と列の key）を入れてください。',
            "\x00\x01\x02" => 'CSV ファイルではないようです。Excel などで「CSV」形式で保存したファイルを選んでください。',
        ];

        foreach ($cases as $csv => $message) {
            $messages = $this->import($csv)->assertUnprocessable()->json('errors.file');
            // 「存在しない列」のように、続けて使える列などを案内するメッセージもあるため、先頭で比べる
            $this->assertNotEmpty(array_filter($messages, fn (string $m) => str_starts_with($m, $message)), "CSV: {$csv}");
        }
        $this->assertSame(0, ChangesetItem::count());
    }

    public function test_rejects_more_than_500_rows(): void
    {
        $lines = ['record_key,power'];
        for ($i = 1; $i <= 501; $i++) {
            $lines[] = sprintf('ab_9%03d,1', $i);
        }

        $this->import(implode("\n", $lines))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => '行が多すぎます（501 行）。1回に取り込めるのは 500 行までです。']);
        $this->assertSame(0, ChangesetItem::count());
    }

    public function test_validates_file(): void
    {
        $this->actingAs($this->editor, 'web')
            ->postJson("/api/changesets/{$this->changeset->id}/masters/abilities/import", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'CSV ファイルは必須です。']);

        $this->import("record_key\nab_001\n", filename: 'data.xlsx')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'CSV ファイルは csv, txt のいずれかの形式のファイルにしてください。']);
    }

    public function test_import_requires_the_same_permission_as_saving(): void
    {
        $this->import("record_key,power\nab_001,1\n", as: User::where('role', UserRole::Approver)->firstOrFail())
            ->assertForbidden();
        $this->assertSame(0, ChangesetItem::count());
    }

    public function test_import_is_only_for_drafts(): void
    {
        $this->changeset->update(['status' => ChangesetStatus::InReview]);

        $this->import("record_key,power\nab_001,1\n")->assertStatus(409);
    }

    public function test_export_returns_excel_friendly_csv_without_deleted_rows(): void
    {
        app(ChangesetRecords::class)->save($this->changeset, MasterTable::where('key', 'stage_enemies')->firstOrFail(), [
            ['record_key' => 'se_0001', 'data' => null],
        ]);

        // 承認者も書き出せる（中身は編集用データの API で見られるもの）
        $response = $this->export('stage_enemies', User::where('role', UserRole::Approver)->firstOrFail())->assertOk();

        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $this->assertSame(
            "attachment; filename=\"changeset{$this->changeset->id}_stage_enemies.csv\"",
            $response->headers->get('Content-Disposition'),
        );
        $csv = $response->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFrecord_key,stage_id,enemy_id,pos_x,pos_y,spawn_frame,respawn\r\n", $csv);
        $this->assertStringNotContainsString("\r\nse_0001,", $csv);
        $this->assertStringContainsString("\r\nse_0002,", $csv);
        $this->assertMatchesRegularExpression('/,(TRUE|FALSE)\r\n/', $csv);
        $this->assertSame(150, substr_count($csv, "\r\n"));
    }

    public function test_exported_csv_can_be_imported_without_changes(): void
    {
        // 追加・更新・空欄を含む状態にしてから書き出す
        $this->import("record_key,name,power,range\nab_001,改名,1,2.5\nab_901,新技,,\n")->assertOk();
        $items = ChangesetItem::orderBy('id')->get(['record_key', 'operation', 'after_data'])->toArray();

        $csv = $this->export()->assertOk()->getContent();

        $this->import($csv)
            ->assertOk()
            ->assertExactJson(['data' => ['created' => 0, 'updated' => 0, 'unchanged' => 21]]);
        $this->assertSame($items, ChangesetItem::orderBy('id')->get(['record_key', 'operation', 'after_data'])->toArray());
    }
}
