<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use Phoenix1331\JevModeration\Exceptions\DecisionsAreAppendOnly;
use Phoenix1331\JevModeration\Models\ModerationDecision;

/**
 * Refuses mass updates and deletes, which skip model events. Inserts and
 * reads behave as normal.
 *
 * @internal
 *
 * @extends Builder<ModerationDecision>
 */
final class DecisionBuilder extends Builder
{
    public function update(array $values): never
    {
        throw DecisionsAreAppendOnly::cannotUpdate();
    }

    public function upsert(array $values, $uniqueBy, $update = null): never
    {
        throw DecisionsAreAppendOnly::cannotUpdate();
    }

    /**
     * @param  array<int, string>|string|null  $column
     */
    public function touch($column = null): never
    {
        throw DecisionsAreAppendOnly::cannotUpdate();
    }

    public function increment($column, $amount = 1, array $extra = []): never
    {
        throw DecisionsAreAppendOnly::cannotUpdate();
    }

    public function decrement($column, $amount = 1, array $extra = []): never
    {
        throw DecisionsAreAppendOnly::cannotUpdate();
    }

    public function incrementEach(array $columns, array $extra = []): never
    {
        throw DecisionsAreAppendOnly::cannotUpdate();
    }

    public function decrementEach(array $columns, array $extra = []): never
    {
        throw DecisionsAreAppendOnly::cannotUpdate();
    }

    public function delete(): never
    {
        throw DecisionsAreAppendOnly::cannotDelete();
    }

    public function forceDelete(): never
    {
        throw DecisionsAreAppendOnly::cannotDelete();
    }
}
