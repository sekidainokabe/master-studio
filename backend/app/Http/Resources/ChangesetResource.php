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
        ];
    }
}
