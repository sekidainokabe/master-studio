<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * ビルドした React（SPA）の index.html を返す。
 * 画面の振り分け（/login、/changesets/1 など）は React Router が行うため、API 以外のパスはすべてここに来る。
 * 開発中は Vite が画面を配信するので、このファイルは本番イメージにだけ存在する（Dockerfile で配置）。
 */
class SpaController extends Controller
{
    public static function indexPath(): string
    {
        return resource_path('spa/index.html');
    }

    public function __invoke(): BinaryFileResponse
    {
        abort_unless(is_file(self::indexPath()), 404);

        // index.html はデプロイのたびに中身（読み込む JS のファイル名）が変わるため、キャッシュさせない
        return response()->file(self::indexPath(), ['Cache-Control' => 'no-cache']);
    }
}
