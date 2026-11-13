<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\ChangesetStatus;
use App\Http\Resources\ChangesetResource;
use App\Models\Changeset;
use App\Models\Release;
use App\Services\AuditLogger;
use App\Services\ChangesetApproval;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 変更セットの状態の移り変わり（docs/SPEC.md 8〜9章）。
 * 下書き →（レビュー依頼）→ レビュー中 →（承認）→ 反映済み
 *                                     →（却下）→ 却下 →（下書きに戻す）→ 下書き
 */
class ChangesetReviewController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function submit(Request $request, Changeset $changeset, ChangesetApproval $approval): ChangesetResource
    {
        return $this->transition($request, $changeset, 'submit', AuditAction::Submitted, function (Changeset $locked) use ($approval) {
            // エラーのある変更セットは、承認者に回さない
            $approval->assertReady($locked);
            $locked->update([
                'status' => ChangesetStatus::InReview,
                'submitted_at' => now(),
                // 却下からの出し直しでは、前回のレビューの結果を消す（経緯は操作履歴に残る）
                'reviewer_id' => null,
                'review_comment' => null,
                'reviewed_at' => null,
            ]);

            return ['item_count' => $locked->items()->count()];
        });
    }

    public function approve(Request $request, Changeset $changeset, ChangesetApproval $approval): ChangesetResource
    {
        Gate::authorize('approve', $changeset);
        $validated = $request->validate(['comment' => ['nullable', 'string', 'max:1000']], [], ['comment' => 'コメント']);

        // ロック・権限の確かめ直し・反映・操作履歴はサービスの中で行う
        $approval->approve($changeset, $request->user(), $validated['comment'] ?? null);

        return ChangesetResource::detail($changeset->refresh());
    }

    public function reject(Request $request, Changeset $changeset): ChangesetResource
    {
        // 権限のない人に入力エラー（422）を見せないよう、先に権限を確かめる
        Gate::authorize('reject', $changeset);
        $validated = $request->validate(['comment' => ['required', 'string', 'max:1000']], [], ['comment' => '却下の理由']);

        return $this->transition($request, $changeset, 'reject', AuditAction::Rejected, function (Changeset $locked) use ($request, $validated) {
            $locked->update([
                'status' => ChangesetStatus::Rejected,
                'reviewer_id' => $request->user()->id,
                'review_comment' => $validated['comment'],
                'reviewed_at' => now(),
            ]);

            return ['comment' => $validated['comment']];
        });
    }

    public function reopen(Request $request, Changeset $changeset): ChangesetResource
    {
        return $this->transition($request, $changeset, 'reopen', AuditAction::Reopened, function (Changeset $locked) {
            $latest = Release::query()->orderByDesc('version')->first();
            $locked->update([
                'status' => ChangesetStatus::Draft,
                // 基準のリリースを最新に付け直す。競合で却下されたときは、今の本番との差分を見直してから出し直す流れになる。
                // 却下の理由（review_comment）は、直すときに読めるよう残しておく
                'base_release_id' => $latest?->id,
            ]);

            return ['base_release_version' => $latest?->version];
        });
    }

    /**
     * 変更セットの行をロックしてから権限と状態を確かめ、状態を変えて操作履歴に残す。
     * 同時に操作されても（例：2人の承認者が同時に却下と承認）、後から来た方は 409 になる
     *
     * @param  Closure(Changeset): array<string, mixed>  $change  状態を変え、操作履歴の detail を返す
     */
    private function transition(Request $request, Changeset $changeset, string $ability, AuditAction $action, Closure $change): ChangesetResource
    {
        DB::transaction(function () use ($request, $changeset, $ability, $action, $change) {
            $locked = Changeset::whereKey($changeset->id)->lockForUpdate()->firstOrFail();
            Gate::authorize($ability, $locked);
            $this->audit->record($request->user(), $action, $locked, $change($locked));
        });

        return ChangesetResource::detail($changeset->refresh());
    }
}
