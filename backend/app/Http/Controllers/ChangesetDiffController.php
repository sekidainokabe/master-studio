<?php

namespace App\Http\Controllers;

use App\Models\Changeset;
use App\Services\ChangesetDiff;
use Illuminate\Http\JsonResponse;

/**
 * 変更セットの差分（今の本番との比較）。ログインしていれば誰でも見られる（承認者のレビューに使う）
 */
class ChangesetDiffController extends Controller
{
    public function __invoke(Changeset $changeset, ChangesetDiff $diff): JsonResponse
    {
        return response()->json(['data' => $diff->of($changeset)]);
    }
}
