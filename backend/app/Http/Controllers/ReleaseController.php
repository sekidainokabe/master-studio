<?php

namespace App\Http\Controllers;

use App\Http\Resources\ReleaseResource;
use App\Models\Release;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * リリース（承認で作られた本番データの版）の一覧と、出力 JSON のダウンロード。
 * ログインしている人なら、役割にかかわらず見られる
 */
class ReleaseController extends Controller
{
    private const PER_PAGE = 20;

    public function index(): AnonymousResourceCollection
    {
        $releases = Release::query()
            // JSON の本文は大きい（数十 KB〜）ため一覧では読み込まず、大きさだけを DB で数える
            ->select(['id', 'version', 'changeset_id', 'released_by', 'created_at'])
            ->selectRaw('LENGTH(content) AS content_bytes')
            ->with(['changeset:id,title', 'releaser:id,name'])
            ->orderByDesc('version')
            ->paginate(self::PER_PAGE);

        return ReleaseResource::collection($releases);
    }

    public function export(Release $release): Response
    {
        // 出力 JSON を DB に保存するようにした前のリリースには、本文がないことがある
        abort_if($release->content === null, 404);

        return response($release->content, 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"release_v{$release->version}.json\"",
        ]);
    }
}
