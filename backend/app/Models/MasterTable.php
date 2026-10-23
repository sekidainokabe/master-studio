<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['key', 'name', 'description'])]
class MasterTable extends Model
{
    /**
     * @return HasMany<MasterColumn, $this>
     */
    public function columns(): HasMany
    {
        return $this->hasMany(MasterColumn::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<MasterRecord, $this>
     */
    public function records(): HasMany
    {
        return $this->hasMany(MasterRecord::class);
    }
}
