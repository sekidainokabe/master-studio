<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Http\Requests\ImportChangesetCsvRequest;
use App\Models\Changeset;
use App\Models\MasterTable;
use App\Services\AuditLogger;
use App\Services\ChangesetCsv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * 変更セットの中の1つのマスタを、CSV で取り込む・書き出す
 */
class ChangesetCsvController extends Controller
{
    public function __construct(
        private readonly ChangesetCsv $csv,
        private readonly AuditLogger $audit,
    ) {}

    /** 取り込んで保存し、追加・更新・変化なしの件数を返す */
    public function import(ImportChangesetCsvRequest $request, Changeset $changeset, MasterTable $masterTable): JsonResponse
    {
        $file = $request->file('file');
        $counts = DB::transaction(function () use ($request, $changeset, $masterTable, $file) {
            $counts = $this->csv->import($changeset, $masterTable, $file->getContent());
            $this->audit->record($request->user(), AuditAction::CsvImported, $changeset, [
                'master' => $masterTable->key,
                'filename' => $file->getClientOriginalName(),
                ...$counts,
            ]);

            return $counts;
        });

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
