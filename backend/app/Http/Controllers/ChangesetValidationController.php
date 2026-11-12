<?php

namespace App\Http\Controllers;

use App\Models\Changeset;
use App\Validation\CrossValidator;
use App\Validation\MergedMasters;
use Illuminate\Http\JsonResponse;

/**
 * 変更セット全体の検証結果（単一セル + 横断）。ログインしていれば誰でも見られる（承認者のレビューにも使う）
 */
class ChangesetValidationController extends Controller
{
    public function __invoke(Changeset $changeset, CrossValidator $validator): JsonResponse
    {
        $errors = $validator->validate(MergedMasters::of($changeset));
        $cross = count(array_filter($errors, fn (array $e) => $e['kind'] === 'cross'));

        return response()->json(['data' => [
            'errors' => $errors,
            'counts' => ['total' => count($errors), 'cell' => count($errors) - $cross, 'cross' => $cross],
        ]]);
    }
}
