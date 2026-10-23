<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['version', 'changeset_id', 'released_by', 'export_path'])]
class Release extends Model
{
    // リリースは作成後に変更しないので updated_at を持たない
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'version' => 'integer',
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
     * @return BelongsTo<User, $this>
     */
    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    /**
     * このリリースで最後に反映されたレコード
     *
     * @return HasMany<MasterRecord, $this>
     */
    public function records(): HasMany
    {
        return $this->hasMany(MasterRecord::class);
    }
}
