<?php

namespace App\Http\Controllers;

use App\Enums\ChangesetStatus;
use App\Http\Resources\ChangesetResource;
use App\Models\Changeset;
use App\Models\Release;
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
    public function submit(Changeset $changeset, ChangesetApproval $approval): ChangesetResource
    {
        return $this->transition($changeset, 'submit', function (Changeset $locked) use ($approval) {
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
        });
    }

    public function approve(Request $request, Changeset $changeset, ChangesetApproval $approval): ChangesetResource
    {
        Gate::authorize('approve', $changeset);
        $validated = $request->validate(['comment' => ['nullable', 'string', 'max:1000']], [], ['comment' => 'コメント']);

        // ロック・権限の確かめ直し・反映はサービスの中で行う
        $approval->approve($changeset, $request->user(), $validated['comment'] ?? null);

        return ChangesetResource::detail($changeset->refresh());
    }

    public function reject(Request $request, Changeset $changeset): ChangesetResource
    {
        // 権限のない人に入力エラー（422）を見せないよう、先に権限を確かめる
        Gate::authorize('reject', $changeset);
        $validated = $request->validate(['comment' => ['required', 'string', 'max:1000']], [], ['comment' => '却下の理由']);

        return $this->transition($changeset, 'reject', fn (Changeset $locked) => $locked->update([
            'status' => ChangesetStatus::Rejected,
            'reviewer_id' => $request->user()->id,
            'review_comment' => $validated['comment'],
            'reviewed_at' => now(),
        ]));
    }

    public function reopen(Changeset $changeset): ChangesetResource
    {
        return $this->transition($changeset, 'reopen', fn (Changeset $locked) => $locked->update([
            'status' => ChangesetStatus::Draft,
            // 基準のリリースを最新に付け直す。競合で却下されたときは、今の本番との差分を見直してから出し直す流れになる。
            // 却下の理由（review_comment）は、直すときに読めるよう残しておく
            'base_release_id' => Release::query()->orderByDesc('version')->value('id'),
        ]));
    }

    /**
     * 変更セットの行をロックしてから権限と状態を確かめ、状態を変える。
     * 同時に操作されても（例：2人の承認者が同時に却下と承認）、後から来た方は 409 になる
     *
     * @param  Closure(Changeset): mixed  $change
     */
    private function transition(Changeset $changeset, string $ability, Closure $change): ChangesetResource
    {
        DB::transaction(function () use ($changeset, $ability, $change) {
            $locked = Changeset::whereKey($changeset->id)->lockForUpdate()->firstOrFail();
            Gate::authorize($ability, $locked);
            $change($locked);
        });

        return ChangesetResource::detail($changeset->refresh());
    }
}
