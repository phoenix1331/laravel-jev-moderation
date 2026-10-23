<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Console;

use Illuminate\Console\Command;
use Phoenix1331\JevModeration\Enums\ModerationMode;
use Phoenix1331\JevModeration\Policies\PolicyRegistry;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

/**
 * Publishes config and migrations, asks for the mode and default policy,
 * optionally migrates, and prints the .env keys to add. With
 * --no-interaction it uses the defaults and migrates only with --migrate.
 *
 * @internal
 */
final class InstallCommand extends Command
{
    protected $signature = 'jev-moderation:install
        {--mode= : post (visible straight away) or pre (hidden until checked)}
        {--policy= : The default policy preset}
        {--migrate : Run the migrations without asking}
        {--force : Overwrite config and migrations already published}';

    protected $description = 'Install Jev Moderation: publish config and migrations and print the .env keys';

    public function handle(PolicyRegistry $policies): int
    {
        $mode = $this->mode();
        $policy = $this->policy($policies);

        if ($mode === null || $policy === null) {
            return self::FAILURE;
        }

        $force = $this->option('force') === true;

        $this->callSilently('vendor:publish', ['--tag' => 'jev-moderation-config', '--force' => $force]);
        $this->callSilently('vendor:publish', ['--tag' => 'jev-moderation-migrations', '--force' => $force]);
        $this->components->info('Published config/jev-moderation.php and the moderation_decisions migration.');

        if ($this->shouldMigrate()) {
            $this->call('migrate');
        }

        $this->components->info('Add these to .env:');
        foreach ([
            'JEV_API_KEY=',
            'JEV_MODEL=jev-latest',
            "JEV_MODERATION_MODE={$mode->value}",
            "JEV_MODERATION_POLICY={$policy}",
            'JEV_MODERATION_DRIVER=jev',
            'JEV_MODERATION_QUEUE=',
        ] as $line) {
            $this->line($line);
        }
        $this->newLine();

        $this->components->info('Next:');
        $this->components->bulletList([
            'Get a key at typesafe.ai and set JEV_API_KEY, then run php artisan jev-moderation:ping',
            "Add use Moderatable and #[Moderate(fields: ['title', 'body'])] to each model to moderate",
            'Run a queue worker: checks run on the queue after each save',
        ]);

        return self::SUCCESS;
    }

    private function mode(): ?ModerationMode
    {
        $given = $this->option('mode');

        if (is_string($given) && $given !== '') {
            return ModerationMode::tryFrom($given) ?? $this->invalid('--mode', $given, array_column(ModerationMode::cases(), 'value'));
        }

        if (! $this->input->isInteractive()) {
            return ModerationMode::Post;
        }

        $chosen = select(
            label: 'When should content be visible?',
            options: [
                ModerationMode::Post->value => 'post: straight away, hidden once blocked or rejected',
                ModerationMode::Pre->value => 'pre: only after it passes or a moderator approves it',
            ],
            default: ModerationMode::Post->value,
        );

        return is_string($chosen) ? ModerationMode::tryFrom($chosen) : null;
    }

    private function policy(PolicyRegistry $policies): ?string
    {
        $names = $policies->names();
        $given = $this->option('policy');

        if (is_string($given) && $given !== '') {
            return in_array($given, $names, true) ? $given : $this->invalid('--policy', $given, $names);
        }

        $default = in_array('community', $names, true) ? 'community' : ($names[0] ?? null);

        if (! $this->input->isInteractive()) {
            return $default;
        }

        $chosen = select(
            label: 'Which policy should models use by default?',
            options: $names,
            default: $default,
            hint: 'community suits forums, chat suits fast messages, strict suits platforms for young people.',
        );

        return is_string($chosen) ? $chosen : null;
    }

    private function shouldMigrate(): bool
    {
        if ($this->option('migrate') === true) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            return false;
        }

        return confirm('Run the migrations now?', default: true);
    }

    /**
     * @param  list<string>  $allowed
     */
    private function invalid(string $option, string $given, array $allowed): null
    {
        $this->components->error(sprintf('%s must be one of: %s. Got [%s].', $option, implode(', ', $allowed), $given));

        return null;
    }
}
