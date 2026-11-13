<?php

namespace App\Http\Resources;

use App\Models\Release;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Release
 */
class ReleaseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'version' => $this->version,
            // シードで作った最初のリリースは、変更セットを通していないので null
            'changeset' => $this->changeset?->only(['id', 'title']),
            'released_by' => $this->releaser?->only(['id', 'name']),
            // 一覧では本文を読まず、DB で数えた大きさ（バイト数）だけを返す
            'content_bytes' => $this->content_bytes === null ? null : (int) $this->content_bytes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
