<?php

namespace App\Models;

use App\Enums\ChangeOperation;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['changeset_id', 'master_table_id', 'record_key', 'operation', 'before_data', 'after_data'])]
class ChangesetItem extends Model
{
    protected function casts(): array
    {
        return [
            'operation' => ChangeOperation::class,
            'before_data' => 'array',
            'after_data' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Changeset, $this>
     */
    public function changeset(): BelongsTo
    {
        return $this->belongsTo(Changeset::class);
    }

    /**
     * @return BelongsTo<MasterTable, $this>
     */
    public function masterTable(): BelongsTo
    {
        return $this->belongsTo(MasterTable::class);
    }
}
