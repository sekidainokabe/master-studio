<?php

namespace App\Http\Resources;

use App\Models\MasterTable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * マスタ一覧の1行。records_count / records_max_updated_at は withCount / withMax で読み込んだときだけ返す。
 *
 * @mixin MasterTable
 */
class MasterTableResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'record_count' => $this->whenCounted('records'),
            'last_updated_at' => $this->whenAggregated(
                'records', 'updated_at', 'max',
                fn (?string $value) => $value === null ? null : Carbon::parse($value)->toIso8601String(),
            ),
        ];
    }
}
