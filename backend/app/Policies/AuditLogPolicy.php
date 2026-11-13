<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * 操作履歴の権限（docs/SPEC.md 8章）。
 * 誰がいつ何をしたかは、レビューや問題の調査をする approver と admin だけが見る
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::Approver, UserRole::Admin], true);
    }
}
