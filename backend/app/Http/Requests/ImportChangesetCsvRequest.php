<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * CSV の取り込み。ファイルの大きさと種類だけをここで確かめ、中身は ChangesetCsv で確かめる
 */
class ImportChangesetCsvRequest extends FormRequest
{
    /** 1MB。500行の CSV には十分な大きさ */
    private const MAX_KILOBYTES = 1024;

    /** 一括保存と同じ権限：権限がなければ 403、下書きでなければ 409（ChangesetPolicy::update） */
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
            'file' => ['required', 'file', 'max:'.self::MAX_KILOBYTES, 'extensions:csv,txt'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['file' => 'CSV ファイル'];
    }
}
