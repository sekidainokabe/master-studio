<?php

namespace App\Http\Requests;

use App\Models\Changeset;
use Illuminate\Foundation\Http\FormRequest;

class StoreChangesetRequest extends FormRequest
{
    /** 権限がなければ 403 を返す（判定は ChangesetPolicy::create） */
    public function authorize(): bool
    {
        return $this->user()->can('create', Changeset::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:10000'],
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
