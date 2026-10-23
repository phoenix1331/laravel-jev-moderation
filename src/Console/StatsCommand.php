<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Phoenix1331\JevModeration\Enums\ModerationSource;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Models\ModerationDecision;
use stdClass;

/**
 * Counts decisions recorded in the last N days: by status, by category for
 * automatic flags and blocks, and how moderators' decisions compare with
 * Jev's.
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

        $this->agreement($since, $type);

        return self::SUCCESS;
    }

    /**
     * Pair each moderator decision in the window with Jev's latest earlier
     * result for the same record, wherever that falls, and count the pairs.
     */
    private function agreement(Carbon $since, ?string $type): void
    {
        $counts = ['upheld' => 0, 'overturned' => 0, 'missed' => 0, 'dismissed' => 0, 'unchecked' => 0];

        foreach ($this->pairs($since, $type) as $pair) {
            $counts[self::outcome($pair->status ?? null, $pair->jev_status ?? null)] += self::number($pair->total ?? 0);
        }

        if (array_sum($counts) === 0) {
            return;
        }

        $this->components->twoColumnDetail('Moderators upheld Jev (rejected)', (string) $counts['upheld']);
        $this->components->twoColumnDetail('Moderators overturned Jev (approved)', (string) $counts['overturned']);

        $checked = $counts['upheld'] + $counts['overturned'];

        if ($checked > 0) {
            $this->components->twoColumnDetail('Upheld share', round($counts['upheld'] / $checked * 100).'%');
        }

        foreach ([
            'missed' => 'Missed by Jev (passed, then rejected)',
            'dismissed' => 'Reports dismissed (passed, then approved)',
            'unchecked' => 'Not checked by Jev',
        ] as $key => $label) {
            if ($counts[$key] > 0) {
                $this->components->twoColumnDetail($label, (string) $counts[$key]);
            }
        }
    }

    /**
     * @return Collection<int, stdClass> moderator status, Jev's status (null when never checked) and a count
     */
    private function pairs(Carbon $since, ?string $type): Collection
    {
        $table = (new ModerationDecision)->getTable();
        $base = ModerationDecision::query()->toBase();

        $latestResult = (clone $base)->from("{$table} as prior")
            ->selectRaw('max(prior.id)')
            ->whereColumn('prior.moderatable_type', 'human.moderatable_type')
            ->whereColumn('prior.moderatable_id', 'human.moderatable_id')
            ->whereColumn('prior.id', '<', 'human.id')
            ->whereIn('prior.source', [ModerationSource::Jev->value, ModerationSource::Rescan->value])
            ->whereIn('prior.status', [ModerationStatus::Passed->value, ModerationStatus::Flagged->value, ModerationStatus::Blocked->value]);

        $jevStatus = (clone $base)->from("{$table} as result")
            ->select('result.status')
            ->where('result.id', '=', $latestResult);

        $humans = (clone $base)->from("{$table} as human")
            ->select('human.status')
            ->selectSub($jevStatus, 'jev_status')
            ->where('human.source', ModerationSource::Human->value)
            ->whereIn('human.status', [ModerationStatus::Approved->value, ModerationStatus::Rejected->value])
            ->where('human.created_at', '>=', $since)
            ->when($type !== null, static fn (Builder $query): Builder => $query->where('human.moderatable_type', $type));

        return (clone $base)->fromSub($humans, 'paired')
            ->selectRaw('status, jev_status, count(*) as total')
            ->groupBy('status', 'jev_status')
            ->get();
    }

    /**
     * @return 'upheld'|'overturned'|'missed'|'dismissed'|'unchecked'
     */
    private static function outcome(mixed $moderator, mixed $jev): string
    {
        $rejected = $moderator === ModerationStatus::Rejected->value;

        if ($jev === null) {
            return 'unchecked';
        }

        if ($jev === ModerationStatus::Passed->value) {
            return $rejected ? 'missed' : 'dismissed';
        }

        return $rejected ? 'upheld' : 'overturned';
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
