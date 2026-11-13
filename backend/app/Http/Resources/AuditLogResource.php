<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // 利用者を消した場合に備え、user_id は null を許している
            'user' => $this->user?->only(['id', 'name']),
            'action' => $this->action->value,
            'action_label' => $this->action->label(),
            'target' => [
                'type' => $this->target_type,
                'id' => $this->target_id,
                // 変更セットのタイトル。削除済みなら null（コントローラーで付ける）
                'title' => $this->target_title,
            ],
            'detail' => $this->detail,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
