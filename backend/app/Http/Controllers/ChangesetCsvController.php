<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportChangesetCsvRequest;
use App\Models\Changeset;
use App\Models\MasterTable;
use App\Services\ChangesetCsv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * 変更セットの中の1つのマスタを、CSV で取り込む・書き出す
 */
class ChangesetCsvController extends Controller
{
    public function __construct(private readonly ChangesetCsv $csv) {}

    /** 取り込んで保存し、追加・更新・変化なしの件数を返す */
    public function import(ImportChangesetCsvRequest $request, Changeset $changeset, MasterTable $masterTable): JsonResponse
    {
        $counts = $this->csv->import($changeset, $masterTable, $request->file('file')->getContent());

        return response()->json(['data' => $counts]);
    }

    /**
     * 編集用データを CSV で返す。中身は編集用データの API で誰でも見られるため、ログインしていれば誰でも書き出せる
     * （承認者が Excel で確かめる使い方もできる）
     */
    public function export(Changeset $changeset, MasterTable $masterTable): Response
    {
        $filename = "changeset{$changeset->id}_{$masterTable->key}.csv";

        return response($this->csv->export($changeset, $masterTable), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
