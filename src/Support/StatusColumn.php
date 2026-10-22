<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;
use Phoenix1331\JevModeration\Models\ModerationDecision;

/**
 * The optional moderation_status column on a moderated table, added with
 * jev-moderation:add-column. When present, every new decision copies its
 * status into it and the visibility scope reads it instead of joining
 * decisions. Generated migrations call backfill() and forget().
 *
 * Detection is cached per process: restart queue workers after migrating,
 * as for any schema change.
 */
final class StatusColumn
{
    public const string NAME = 'moderation_status';

    private const int CHUNK = 1000;

    /** @var array<string, bool> connection|table to whether the column exists */
    private static array $known = [];

    public static function exists(Model $model): bool
    {
        $key = $model->getConnectionName().'|'.$model->getTable();

        return self::$known[$key] ??= Schema::connection($model->getConnectionName())->hasColumn($model->getTable(), self::NAME);
    }

    /**
     * Copy a new decision's status onto its model's row, if the table has
     * the column. A plain query: no model events, no updated_at.
     */
    public static function sync(ModerationDecision $decision): void
    {
        $model = self::modelFor($decision->moderatable_type);

        if ($model === null || ! self::exists($model)) {
            return;
        }

        $model->getConnection()->table($model->getTable())
            ->where($model->getKeyName(), $decision->moderatable_id)
            ->update([self::NAME => $decision->status->value]);
    }

    /**
     * Set the column on every row of the table from its latest decision.
     */
    public static function backfill(string $table): void
    {
        self::forget($table);

        $types = ModerationDecision::query()->toBase()->distinct()->pluck('moderatable_type');

        foreach ($types as $type) {
            $model = is_string($type) ? self::modelFor($type) : null;

            if ($model === null || $model->getTable() !== $table) {
                continue;
            }

            $latest = ModerationDecision::query()->toBase()
                ->whereIn('id', ModerationDecision::query()->toBase()->selectRaw('max(id)')->where('moderatable_type', $type)->groupBy('moderatable_id'))
                ->pluck('status', 'moderatable_id');

            foreach ($latest->groupBy(static fn (mixed $status): string => is_string($status) ? $status : '', true) as $status => $rows) {
                foreach (array_chunk($rows->keys()->all(), self::CHUNK) as $ids) {
                    $model->getConnection()->table($table)->whereIn($model->getKeyName(), $ids)->update([self::NAME => $status]);
                }
            }
        }
    }

    /**
     * Drop cached detection for a table (every connection), or all tables.
     */
    public static function forget(?string $table = null): void
    {
        self::$known = $table === null ? [] : array_filter(
            self::$known,
            static fn (string $key): bool => ! str_ends_with($key, '|'.$table),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private static function modelFor(string $type): ?Model
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        return class_exists($class) && is_subclass_of($class, Model::class) ? new $class : null;
    }
}
