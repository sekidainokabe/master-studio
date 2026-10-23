<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    /**
     * アプリと DB の疎通状態を返す。DB に届かない場合は 503 を返す。
     */
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('SELECT 1');
        } catch (Throwable) {
            return response()->json(['status' => 'error', 'database' => 'unreachable'], 503);
        }

        return response()->json(['status' => 'ok', 'database' => 'ok']);
    }
}
