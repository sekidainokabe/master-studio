<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * 利用者の一覧（操作履歴の「利用者」で絞り込むための選択肢）。
 * 今は操作履歴でしか使わないため、見られる人も操作履歴と同じにする。メールアドレスは返さない
 */
class UserController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', AuditLog::class);

        $users = User::query()->orderBy('id')->get(['id', 'name', 'role'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role->value]);

        return response()->json(['data' => $users]);
    }
}
