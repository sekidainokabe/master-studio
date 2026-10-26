<?php

namespace App\Http\Resources;

use App\Models\MasterRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MasterRecord
 */
class MasterRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'record_key' => $this->record_key,
            'data' => $this->data,
            'updated_at' => $this->updated_at?->toIso8601String(),
            'updated_by' => $this->whenLoaded('updater', fn () => $this->updater?->only(['id', 'name'])),
        ];
    }
}
