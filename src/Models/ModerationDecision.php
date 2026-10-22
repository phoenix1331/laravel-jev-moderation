<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Phoenix1331\JevModeration\Database\Factories\ModerationDecisionFactory;
use Phoenix1331\JevModeration\Enums\ModerationSource;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Models\Builders\DecisionBuilder;
use Phoenix1331\JevModeration\Support\StatusColumn;

/**
 * One row per check, report or review, read through a Moderatable model's
 * moderationDecisions and latestModeration relations. Public API for reads:
 * columns change only with a minor release and an UPGRADING.md entry.
 * Append-only: saving an existing row, deleting one, or a mass update or
 * delete throws DecisionsAreAppendOnly.
 *
 * @property int $id
 * @property string $moderatable_type
 * @property int|string $moderatable_id
 * @property ModerationStatus $status
 * @property ModerationSource $source
 * @property string $policy
 * @property string $policy_version
 * @property string|null $model
 * @property string|null $category
 * @property float|null $confidence
 * @property string $content_hash
 * @property string|null $content
 * @property string|null $reviewed_by_type
 * @property int|string|null $reviewed_by_id
 * @property string|null $note
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ModerationDecision extends Model
{
    /** @use HasFactory<ModerationDecisionFactory> */
    use HasFactory;

    protected $table = 'moderation_decisions';

    protected $guarded = ['id'];

    /**
     * Saving or deleting an existing row ends up in this builder too, so
     * it is the single guard for every write path.
     *
     * @param  QueryBuilder  $query
     */
    public function newEloquentBuilder($query): DecisionBuilder
    {
        return new DecisionBuilder($query);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function moderatable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function reviewedBy(): MorphTo
    {
        return $this->morphTo('reviewed_by');
    }

    protected static function booted(): void
    {
        self::created(static fn (self $decision) => StatusColumn::sync($decision));
    }

    protected static function newFactory(): ModerationDecisionFactory
    {
        return ModerationDecisionFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ModerationStatus::class,
            'source' => ModerationSource::class,
            'confidence' => 'float',
            'meta' => 'array',
        ];
    }
}
