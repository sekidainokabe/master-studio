<?php

namespace App\Policies;

use App\Enums\ChangesetStatus;
use App\Enums\UserRole;
use App\Models\Changeset;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * 変更セットの権限（docs/SPEC.md 8章）。
 * 一覧・詳細・編集用データの閲覧はログインしていれば誰でもできるため、ここでは扱わない。承認・却下などは第6週に追加する。
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
        $canEdit = $user->role === UserRole::Admin
            || ($user->role === UserRole::Editor && $changeset->author_id === $user->id);

        if (! $canEdit) {
            return Response::deny('この変更セットを編集する権限がありません。');
        }
        if ($changeset->status !== ChangesetStatus::Draft) {
            return Response::denyWithStatus(409, '下書きの変更セットだけ編集できます。');
        }

        return Response::allow();
    }
}
