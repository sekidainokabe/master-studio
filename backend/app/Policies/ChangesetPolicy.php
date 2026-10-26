<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * 変更セットの権限（docs/SPEC.md 8章）。
 * 一覧・詳細はログインしていれば誰でも見られるため、ここでは扱わない。承認・却下などは第6週に追加する。
 */
class ChangesetPolicy
{
    /** 作成できるのは editor と admin（approver は承認する側なので作成しない） */
    public function create(User $user): bool
    {
        return in_array($user->role, [UserRole::Editor, UserRole::Admin], true);
    }
}
