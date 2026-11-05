<?php

namespace App\Http\Requests;

use App\Models\MasterTable;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * 編集用データの一括保存。
 * ここで拒否するのは「データとして扱えない入力」だけで、型や範囲などのルール違反は保存したうえでエラーとして返す（docs/plan-week4.md）
 */
class UpdateChangesetRecordsRequest extends FormRequest
{
    private const MAX_RECORDS = 500;

    /** 権限がなければ 403、下書きでなければ 409（判定は ChangesetPolicy::update） */
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('changeset'));
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'records' => ['present', 'list', 'max:'.self::MAX_RECORDS],
            'records.*' => ['array:record_key,data'],
            // record_key は参照列から指されるため、英小文字・数字・_ だけにする（シードの ab_001 などと同じ形）
            'records.*.record_key' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/', 'distinct'],
            // null は行の削除
            'records.*.data' => ['present', 'nullable', 'array'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var MasterTable $table */
            $table = $this->route('masterTable');
            $columnKeys = $table->columns->pluck('key')->all();

            foreach ($this->input('records') as $i => $record) {
                foreach ($record['data'] ?? [] as $key => $value) {
                    if (! in_array($key, $columnKeys, true)) {
                        $validator->errors()->add("records.{$i}.data", "存在しない列です：{$key}");
                    } elseif (! is_scalar($value) && $value !== null) {
                        $validator->errors()->add("records.{$i}.data.{$key}", '値には文字列・数値・真偽値・null のいずれかを指定してください。');
                    }
                }
            }
        }];
    }

    /**
     * @return list<array{record_key: string, data: array<string, mixed>|null}>
     */
    public function records(): array
    {
        return $this->validated('records');
    }
}
