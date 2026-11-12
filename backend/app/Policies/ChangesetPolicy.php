<?php

namespace App\Policies;

use App\Enums\ChangesetStatus;
use App\Enums\UserRole;
use App\Models\Changeset;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * 変更セットの権限（docs/SPEC.md 8章）。
 * 一覧・詳細・編集用データの閲覧はログインしていれば誰でもできるため、ここでは扱わない。
 */
class ChangesetPolicy
{
    /** 作成できるのは editor と admin（approver は承認する側なので作成しない） */
    public function create(User $user): bool
    {
        return in_array($user->role, [UserRole::Editor, UserRole::Admin], true);
    }

    /**
     * データの編集。editor は自分の変更セットだけ、admin はすべて。
     * 権限があっても、レビュー中・却下・反映済みのものは編集できない。
     * 「権限がない（403）」と「今の状態では操作できない（409）」は、画面での案内が違うため分けて返す
     */
    public function update(User $user, Changeset $changeset): Response
    {
        if (! $this->isOwnerOrAdmin($user, $changeset)) {
            return Response::deny('この変更セットを編集する権限がありません。');
        }
        if ($changeset->status !== ChangesetStatus::Draft) {
            return Response::denyWithStatus(409, '下書きの変更セットだけ編集できます。');
        }

        return Response::allow();
    }

    /** 削除も編集と同じ条件（下書きのうちは作成者が取り下げられる） */
    public function delete(User $user, Changeset $changeset): Response
    {
        return $this->update($user, $changeset);
    }

    /** レビュー依頼。編集と同じ人が、下書きのときにできる */
    public function submit(User $user, Changeset $changeset): Response
    {
        if (! $this->isOwnerOrAdmin($user, $changeset)) {
            return Response::deny('この変更セットのレビューを依頼する権限がありません。');
        }
        if ($changeset->status !== ChangesetStatus::Draft) {
            return Response::denyWithStatus(409, '下書きの変更セットだけレビューを依頼できます。');
        }

        return Response::allow();
    }

    public function approve(User $user, Changeset $changeset): Response
    {
        return $this->review($user, $changeset, '承認');
    }

    public function reject(User $user, Changeset $changeset): Response
    {
        return $this->review($user, $changeset, '却下');
    }

    /** 却下された変更セットを、作成者が直すために下書きへ戻す */
    public function reopen(User $user, Changeset $changeset): Response
    {
        if (! $this->isOwnerOrAdmin($user, $changeset)) {
            return Response::deny('この変更セットを下書きに戻す権限がありません。');
        }
        if ($changeset->status !== ChangesetStatus::Rejected) {
            return Response::denyWithStatus(409, '却下された変更セットだけ下書きに戻せます。');
        }

        return Response::allow();
    }

    /**
     * 承認・却下。approver と admin が、レビュー中のときにできる。
     * 自分が作った変更セットは、admin でも承認・却下できない（別の人が確かめる、という承認の意味をなくさないため）
     */
    private function review(User $user, Changeset $changeset, string $verb): Response
    {
        if (! in_array($user->role, [UserRole::Approver, UserRole::Admin], true)) {
            return Response::deny("変更セットを{$verb}する権限がありません。");
        }
        if ($changeset->author_id === $user->id) {
            return Response::deny("自分が作成した変更セットは{$verb}できません。");
        }
        if ($changeset->status !== ChangesetStatus::InReview) {
            return Response::denyWithStatus(409, "レビュー中の変更セットだけ{$verb}できます。");
        }

        return Response::allow();
    }

    /** 作成者（editor）本人か admin */
    private function isOwnerOrAdmin(User $user, Changeset $changeset): bool
    {
        return $user->role === UserRole::Admin
            || ($user->role === UserRole::Editor && $changeset->author_id === $user->id);
    }
}
