<?php

namespace App\Http\Controllers;

use App\Http\Resources\MasterColumnResource;
use App\Http\Resources\MasterRecordResource;
use App\Http\Resources\MasterTableResource;
use App\Models\MasterTable;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * マスタの定義と本番データの参照（読み取り専用）。
 */
class MasterController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        // 件数と最終更新日時はサブクエリで一緒に取得し、マスタごとに SQL を発行しない（N+1 を避ける）
        $tables = MasterTable::query()
            ->withCount('records')
            ->withMax('records', 'updated_at')
            ->orderBy('id')
            ->get();

        return MasterTableResource::collection($tables);
    }

    public function schema(MasterTable $masterTable): AnonymousResourceCollection
    {
        return MasterColumnResource::collection($masterTable->columns()->with('refMasterTable')->get());
    }

    public function records(MasterTable $masterTable): AnonymousResourceCollection
    {
        $records = $masterTable->records()
            ->with('updater:id,name')
            ->orderBy('record_key')
            ->get();

        return MasterRecordResource::collection($records);
    }
}
