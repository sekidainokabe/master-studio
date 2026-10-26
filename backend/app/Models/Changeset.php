<?php

namespace App\Models;

use App\Enums\ChangesetStatus;
use Database\Factories\ChangesetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'title', 'description', 'status', 'author_id', 'reviewer_id', 'base_release_id',
    'review_comment', 'submitted_at', 'reviewed_at',
])]
class Changeset extends Model
{
    /** @use HasFactory<ChangesetFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ChangesetStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * 作成時点のリリース（競合検出に使う）
     *
     * @return BelongsTo<Release, $this>
     */
    public function baseRelease(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'base_release_id');
    }

    /**
     * @return HasMany<ChangesetItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ChangesetItem::class);
    }
}
