<?php

namespace App\Http\Resources;

use App\Models\Changeset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Changeset
 */
class ChangesetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'author' => $this->whenLoaded('author', fn () => $this->author->only(['id', 'name'])),
            'reviewer' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->only(['id', 'name'])),
            'base_release_version' => $this->whenLoaded('baseRelease', fn () => $this->baseRelease?->version),
            'item_count' => $this->whenCounted('items'),
            'review_comment' => $this->review_comment,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            // 画面でボタンを出すかどうかの判断に使う。最終的にはサーバーが Policy で拒否する
            'can' => collect(['update', 'delete', 'submit', 'approve', 'reject', 'reopen'])
                ->mapWithKeys(fn (string $ability) => [$ability => $request->user()?->can($ability, $this->resource) ?? false])
                ->all(),
        ];
    }

    /** 詳細画面用。作成者・承認者・基準のリリース・変更の件数を読み込んでから返す */
    public static function detail(Changeset $changeset): self
    {
        return new self(
            $changeset->load(['author:id,name', 'reviewer:id,name', 'baseRelease:id,version'])->loadCount('items'),
        );
    }
}
