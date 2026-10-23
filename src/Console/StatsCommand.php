<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Phoenix1331\JevModeration\Enums\ModerationSource;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Models\ModerationDecision;

/**
 * Counts decisions recorded in the last N days: by status, by category for
 * automatic flags and blocks, and how often moderators agreed.
 *
 * @internal
 */
final class StatsCommand extends Command
{
    protected $signature = 'jev-moderation:stats
        {--days=7 : How many days back to count}
        {--model= : Only this model class or morph alias}';

    protected $description = 'Count moderation decisions per status and category';

    public function handle(): int
    {
        $days = $this->option('days');

        if (! is_string($days) || ! ctype_digit($days) || (int) $days < 1) {
            $this->components->error('--days must be a positive whole number.');

            return self::FAILURE;
        }

        $type = $this->morphType();

        if ($type === false) {
            return self::FAILURE;
        }

        $since = Carbon::now()->subDays((int) $days);
        $scope = $type === null ? 'all models' : $type;

        if ($this->decisions($since, $type)->doesntExist()) {
            $this->components->info("No moderation decisions in the last {$days} days for {$scope}.");

            return self::SUCCESS;
        }

        $this->components->info("Moderation decisions since {$since->toDateString()} for {$scope}");

        $byStatus = $this->decisions($since, $type)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $this->table(['Status', 'Decisions'], array_map(
            static fn (ModerationStatus $status): array => [$status->value, self::number($byStatus[$status->value] ?? 0)],
            ModerationStatus::cases(),
        ));

        $byCategory = $this->decisions($since, $type)
            ->whereIn('source', [ModerationSource::Jev->value, ModerationSource::Rescan->value])
            ->whereIn('status', [ModerationStatus::Flagged->value, ModerationStatus::Blocked->value])
            ->selectRaw('category, status, count(*) as total')
            ->groupBy('category', 'status')
            ->get();

        $rows = [];

        foreach ($byCategory as $row) {
            $category = is_string($row->category ?? null) ? $row->category : '-';
            $rows[$category] ??= [$category, 0, 0];
            $rows[$category][($row->status ?? null) === ModerationStatus::Flagged->value ? 1 : 2] += self::number($row->total ?? 0);
        }

        ksort($rows);

        if ($rows !== []) {
            $this->table(['Category', 'Flagged', 'Blocked'], array_values($rows));
        }

        $approved = self::number($byStatus[ModerationStatus::Approved->value] ?? 0);
        $rejected = self::number($byStatus[ModerationStatus::Rejected->value] ?? 0);

        if ($approved + $rejected > 0) {
            $this->components->twoColumnDetail('Moderators upheld (rejected)', (string) $rejected);
            $this->components->twoColumnDetail('Moderators overturned (approved)', (string) $approved);
            $this->components->twoColumnDetail('Upheld share', round($rejected / ($approved + $rejected) * 100).'%');
        }

        return self::SUCCESS;
    }

    /**
     * Drivers return counts as integers or numeric strings.
     */
    private static function number(mixed $value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }

    private function decisions(Carbon $since, ?string $type): Builder
    {
        $query = ModerationDecision::query()->toBase()->where('created_at', '>=', $since);

        return $type === null ? $query : $query->where('moderatable_type', $type);
    }

    /**
     * @return string|null|false the stored morph type, null for every model, false when invalid
     */
    private function morphType(): string|false|null
    {
        $given = $this->option('model');

        if (! is_string($given) || $given === '') {
            return null;
        }

        $class = Relation::getMorphedModel($given) ?? $given;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            $this->components->error("[{$given}] is not a model.");

            return false;
        }

        return (new $class)->getMorphClass();
    }
}
