<?php

namespace App\Http\Resources;

use App\Enums\ColumnType;
use App\Models\MasterColumn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 列定義。画面側は id を扱わずに済むよう、参照先マスタは key で返す。
 *
 * @mixin MasterColumn
 */
class MasterColumnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'type' => $this->type->value,
            'is_required' => $this->is_required,
            'min_value' => $this->min_value,
            'max_value' => $this->max_value,
            'max_length' => $this->max_length,
            'enum_values' => $this->type === ColumnType::Enum ? $this->enum_values : null,
            'ref_master_key' => $this->type === ColumnType::Ref ? $this->refMasterTable?->key : null,
        ];
    }
}
