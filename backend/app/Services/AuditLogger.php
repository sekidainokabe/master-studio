<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Changeset;
use App\Models\User;

/**
 * 操作履歴（誰が・いつ・何をしたか）を残す。
 *
 * モデルのイベントで自動的に残すのではなく、各操作から明示的に呼ぶ。
 * 「一括保存で追加3件・更新2件」のように、行ごとではなく操作の単位で意味のある内容を残すため。
 * 呼ぶ側は、操作と同じトランザクションの中で呼ぶ（操作だけが成功して履歴が残らない、を防ぐ）
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $detail  操作ごとの補足（件数、コメントなど）
     */
    public function record(User $user, AuditAction $action, Changeset|User $target, ?array $detail = null): AuditLog
    {
        return AuditLog::create([
            'user_id' => $user->id,
            'action' => $action,
            'target_type' => $target instanceof Changeset ? 'changeset' : 'user',
            'target_id' => $target->id,
            'detail' => $detail,
        ]);
    }
}
