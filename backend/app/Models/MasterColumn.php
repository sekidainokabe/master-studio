<?php

namespace App\Models;

use App\Enums\ColumnType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'master_table_id', 'key', 'name', 'type', 'is_required', 'min_value', 'max_value',
    'max_length', 'enum_values', 'ref_master_table_id', 'sort_order',
])]
class MasterColumn extends Model
{
    protected function casts(): array
    {
        return [
            'type' => ColumnType::class,
            'is_required' => 'boolean',
            'min_value' => 'float',
            'max_value' => 'float',
            'max_length' => 'integer',
            'enum_values' => 'array',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<MasterTable, $this>
     */
    public function masterTable(): BelongsTo
    {
        return $this->belongsTo(MasterTable::class);
    }

    /**
     * type=ref のときの参照先マスタ
     *
     * @return BelongsTo<MasterTable, $this>
     */
    public function refMasterTable(): BelongsTo
    {
        return $this->belongsTo(MasterTable::class, 'ref_master_table_id');
    }
}
