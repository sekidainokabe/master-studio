<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Changeset;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * 操作履歴の一覧（approver と admin だけ）。利用者・操作・期間で絞り込む
 */
class AuditLogController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', AuditLog::class);

        // 期間は日付ではなく日時で受け取る。サーバーは利用者のタイムゾーンを知らないため、
        // 「その日の 0 時」を画面側で日時にして送ってもらう（日本なら 2026-11-13T00:00:00+09:00）
        $filters = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'action' => ['nullable', Rule::enum(AuditAction::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after:from'],
        ]);

        $logs = AuditLog::query()
            ->with('user:id,name')
            ->when($filters['user_id'] ?? null, fn ($q, int|string $userId) => $q->where('user_id', $userId))
            ->when($filters['action'] ?? null, fn ($q, string $action) => $q->where('action', $action))
            // DB には UTC で入っているので、受け取った日時を UTC に直して比べる。to はその時刻を含まない
            ->when($filters['from'] ?? null, fn ($q, string $from) => $q->where('created_at', '>=', CarbonImmutable::parse($from)->utc()))
            ->when($filters['to'] ?? null, fn ($q, string $to) => $q->where('created_at', '<', CarbonImmutable::parse($to)->utc()))
            // 記録した順に id が増えるので、id の降順で新しい順になる（created_at には索引がない）
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // 対象の変更セットのタイトルを、ページ内の分だけ1回の SQL で取る。削除済みのものは含まれない
        $changesetIds = $logs->getCollection()->where('target_type', 'changeset')->pluck('target_id')->unique();
        $titles = Changeset::whereIn('id', $changesetIds)->pluck('title', 'id');
        foreach ($logs as $log) {
            // 保存しない、表示のためだけの値
            $log->target_title = $log->target_type === 'changeset' ? $titles->get($log->target_id) : null;
        }

        return AuditLogResource::collection($logs);
    }
}
