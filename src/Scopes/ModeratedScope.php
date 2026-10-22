<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Scopes;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Moderation\Visibility;

/**
 * Hides content its latest decision makes invisible under the configured
 * mode and fail setting. Mode is read per query, so config changes apply.
 * Removed with withoutModeration().
 *
 * @internal
 *
 * @template TModel of Model
 *
 * @implements Scope<TModel>
 */
final class ModeratedScope implements Scope
{
    /**
     * @param  Builder<covariant TModel>  $builder
     * @param  TModel  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        $visibility = Visibility::fromConfig(app(Repository::class));

        $builder->where(static fn (Builder $query) => $query
            ->whereDoesntHave('moderationDecisions')
            ->orWhereHas('latestModeration', static fn (Builder $latest) => $latest->whereIn(
                'status',
                array_map(static fn (ModerationStatus $status): string => $status->value, $visibility->visibleStatuses()),
            )));
    }
}
