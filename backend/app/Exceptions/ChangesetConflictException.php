<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * 承認しようとした変更セットが、作成後の本番の変更とぶつかっている（競合）。
 * 何も反映せずに 409 で返し、どの行がぶつかったかを画面に示す
 */
class ChangesetConflictException extends RuntimeException
{
    /**
     * @param  list<array{master: string, record_key: string, message: string}>  $conflicts
     */
    public function __construct(public readonly array $conflicts)
    {
        parent::__construct('変更セットが本番データの変更と競合しています。');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => 'この変更セットを作成したあとに、本番データが変わった行があります。却下して、作成者が下書きに戻してから見直してください。',
            'conflicts' => $this->conflicts,
        ], 409);
    }
}
