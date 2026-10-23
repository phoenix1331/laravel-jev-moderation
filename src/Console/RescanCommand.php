<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Phoenix1331\JevModeration\Enums\ModerationSource;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Moderation\ModerationSubject;
use Phoenix1331\JevModeration\Moderation\Moderator;
use Phoenix1331\JevModeration\Scopes\ModeratedScope;
use Throwable;

use function Laravel\Prompts\confirm;

/**
 * Re-checks a model's content, e.g. after a threshold or policy change.
 * Each check is a new pending decision with source rescan, queued like a
 * save. By default skips content a moderator approved or rejected.
 *
 * @internal
 */
final class RescanCommand extends Command
{
    /** Never overridden by a bulk re-check unless asked for by --status. */
    private const array HUMAN = [ModerationStatus::Approved, ModerationStatus::Rejected];

    protected $signature = 'jev-moderation:rescan
        {model : The Moderatable model class or morph alias, e.g. App\\Models\\ForumPost}
        {--since= : Only models created on or after this date}
        {--status= : Only models whose latest status is one of these, comma separated (default: all but approved and rejected)}
        {--chunk=500 : Models read per query}
        {--dry-run : Count what would be re-checked, and stop}';

    protected $description = 'Re-check moderated content, e.g. after changing thresholds';

    public function handle(Moderator $moderator): int
    {
        $model = $this->model();
        $statuses = $this->statuses();
        $since = $this->since();
        $chunk = $this->chunk();

        if ($model === null || $statuses === false || $since === false || $chunk === null) {
            return self::FAILURE;
        }

        $query = $this->query($model, $statuses, $since);
        $count = $query->count();
        $label = $model::class;

        if ($count === 0) {
            $this->components->info("Nothing to re-check for {$label}.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run') === true) {
            $this->components->info("{$count} {$label} would be re-checked.");

            return self::SUCCESS;
        }

        if ($this->input->isInteractive() && ! confirm("Re-check {$count} {$label}? Each check is a billed Jev call.", default: true)) {
            return self::SUCCESS;
        }

        $submitted = 0;

        $query->chunkById($chunk, function (Collection $models) use ($moderator, &$submitted): void {
            foreach ($models as $record) {
                $subject = ModerationSubject::for($record);

                if ($subject !== null && $moderator->submit($subject, ModerationSource::Rescan) !== null) {
                    $submitted++;
                }
            }
        });

        $this->components->info(sprintf('Queued %d checks for %s%s.', $submitted, $label, $submitted < $count ? sprintf(' (%d had nothing to read)', $count - $submitted) : ''));

        return self::SUCCESS;
    }

    /**
     * @param  list<ModerationStatus>|null  $statuses
     * @return Builder<Model>
     */
    private function query(Model $model, ?array $statuses, ?Carbon $since): Builder
    {
        $query = $model->newQuery()->withoutGlobalScope(ModeratedScope::class);

        $query = $statuses === null
            ? $query->whereDoesntHave('latestModeration', static fn (Builder $latest) => $latest->whereIn('status', self::values(self::HUMAN)))
            : $query->whereHas('latestModeration', static fn (Builder $latest) => $latest->whereIn('status', self::values($statuses)));

        if ($since !== null) {
            $query->where($model->qualifyColumn($model->getCreatedAtColumn() ?? 'created_at'), '>=', $since);
        }

        return $query;
    }

    /**
     * @param  list<ModerationStatus>  $statuses
     * @return list<string>
     */
    private static function values(array $statuses): array
    {
        return array_map(static fn (ModerationStatus $status): string => $status->value, $statuses);
    }

    private function model(): ?Model
    {
        $given = $this->argument('model');
        $class = Relation::getMorphedModel($given) ?? $given;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class) || ! method_exists($class, 'toModerationSubject')) {
            $this->components->error("[{$given}] is not a model using the Moderatable trait.");

            return null;
        }

        return new $class;
    }

    /**
     * @return list<ModerationStatus>|null|false null for the default, false when invalid
     */
    private function statuses(): array|false|null
    {
        $given = $this->option('status');

        if (! is_string($given) || trim($given) === '') {
            return null;
        }

        $statuses = [];

        foreach (array_map(trim(...), explode(',', $given)) as $value) {
            $status = ModerationStatus::tryFrom($value);

            if ($status === null) {
                $this->components->error(sprintf('Unknown status [%s]. Use: %s.', $value, implode(', ', array_column(ModerationStatus::cases(), 'value'))));

                return false;
            }

            $statuses[] = $status;
        }

        return $statuses;
    }

    private function since(): Carbon|false|null
    {
        $given = $this->option('since');

        if (! is_string($given) || $given === '') {
            return null;
        }

        try {
            return Carbon::parse($given);
        } catch (Throwable) {
            $this->components->error("--since [{$given}] is not a date.");

            return false;
        }
    }

    private function chunk(): ?int
    {
        $given = $this->option('chunk');

        if (! is_string($given) || ! ctype_digit($given) || (int) $given < 1) {
            $this->components->error('--chunk must be a positive whole number.');

            return null;
        }

        return (int) $given;
    }
}
