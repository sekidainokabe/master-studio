<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateChangesetRecordsRequest;
use App\Models\Changeset;
use App\Models\ChangesetItem;
use App\Models\MasterTable;
use App\Services\ChangesetRecords;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * 変更セットの中での、マスタのデータの閲覧・編集（docs/SPEC.md 7章）
 */
class ChangesetRecordController extends Controller
{
    public function __construct(private readonly ChangesetRecords $records) {}

    /** 本番データに変更を重ねた編集用データ。ログインしていれば誰でも見られる（承認者のレビューにも使う） */
    public function index(Changeset $changeset, MasterTable $masterTable): JsonResponse
    {
        return $this->rows($changeset, $masterTable);
    }

    /** 複数行の追加・更新・削除をまとめて保存し、保存後の編集用データを返す */
    public function update(UpdateChangesetRecordsRequest $request, Changeset $changeset, MasterTable $masterTable): JsonResponse
    {
        $this->records->save($changeset, $masterTable, $request->records());

        return $this->rows($changeset, $masterTable);
    }

    /** 変更を1件取り消す（本番の値に戻す） */
    public function destroyItem(Changeset $changeset, ChangesetItem $item): Response
    {
        Gate::authorize('update', $changeset);

        $item->delete();
        $changeset->touch();

        return response()->noContent();
    }

    private function rows(Changeset $changeset, MasterTable $masterTable): JsonResponse
    {
        // 列と本番データは1回ずつまとめて読み込み、行ごとに SQL を発行しない
        $masterTable->load(['columns', 'records']);

        return response()->json(['data' => $this->records->rows($changeset, $masterTable)]);
    }
}
