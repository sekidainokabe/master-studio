<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** 変更セットのタイトル・説明の変更。送られてきた項目だけを変える */
class UpdateChangesetRequest extends FormRequest
{
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
            // sometimes：項目が送られてきたときだけ検査する（説明だけの変更もできるように）
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'タイトル',
            'description' => '説明',
        ];
    }
}
