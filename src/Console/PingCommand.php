<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Phoenix1331\JevModeration\Drivers\DriverManager;
use Phoenix1331\JevModeration\Drivers\JevDriver;
use Phoenix1331\JevModeration\Exceptions\DriverException;
use Phoenix1331\JevModeration\JevModerationManager;

/**
 * Checks the driver works end to end: lists models, classifies one harmless
 * sample, and warns when Jev's model setting is an alias. Never prints the
 * API key.
 *
 * @internal
 */
final class PingCommand extends Command
{
    public const string SAMPLE = 'Is a Seiko 5 still a good first mechanical watch?';

    protected $signature = 'jev-moderation:ping
        {--policy= : The policy to classify the sample with (default jev-moderation.policy)}';

    protected $description = 'Check the moderation driver and key with one sample classification';

    public function handle(Repository $config, DriverManager $drivers, JevModerationManager $moderation): int
    {
        $driverName = $drivers->getDefaultDriver();
        $model = $config->get('jev-moderation.drivers.jev.model');
        $isJev = $driverName === JevDriver::NAME;

        $this->components->twoColumnDetail('Driver', $driverName);

        if ($isJev) {
            $key = $config->get('jev-moderation.drivers.jev.key');
            $url = $config->get('jev-moderation.drivers.jev.base_url');
            $this->components->twoColumnDetail('Model', is_string($model) ? $model : '-');
            $this->components->twoColumnDetail('Base URL', is_string($url) ? $url : '-');
            $this->components->twoColumnDetail('API key', is_string($key) && trim($key) !== '' ? '<fg=green>set</>' : '<fg=red>missing</>');
        }

        try {
            $models = $drivers->driver()->models();
            $this->components->twoColumnDetail('Models available', $models === [] ? '-' : implode(', ', $models));

            $policy = $this->option('policy');
            $result = $moderation->classify(self::SAMPLE, is_string($policy) && $policy !== '' ? $policy : null);
        } catch (DriverException $e) {
            $this->newLine();
            $this->components->error($e->getMessage());

            if ($e->requestId !== null) {
                $this->components->twoColumnDetail('Request id', $e->requestId);
            }

            return self::FAILURE;
        } catch (InvalidArgumentException $e) {
            $this->newLine();
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $answer = $result->classification;

        $this->newLine();
        $this->components->info('Sample classified: "'.self::SAMPLE.'"');
        $this->components->twoColumnDetail('Policy', $result->policy);
        $this->components->twoColumnDetail('Outcome', "{$result->status()->value} ({$result->verdict->reason->value})");
        $this->components->twoColumnDetail('Deciding category', sprintf('%s at %.2f', $result->verdict->category, $result->verdict->probability));
        $this->components->twoColumnDetail('Jev answered', sprintf('%s, confidence %.2f', $answer->choice, $answer->confidence));
        $this->components->twoColumnDetail('Answered by', $answer->model ?? '-');
        $this->components->twoColumnDetail('Latency', "{$answer->latencyMs} ms");
        $this->components->twoColumnDetail('Input tokens', $answer->inputTokens === null ? '-' : (string) $answer->inputTokens);
        $this->components->twoColumnDetail('Request id', $answer->requestId ?? '-');

        if ($isJev && is_string($model) && self::isAlias($model)) {
            $this->newLine();
            $this->components->warn("{$model} is an alias: it moves when TypeSafe ships a release, and answers can change with it.");
            $this->line('  Once your thresholds are tuned, pin the version: JEV_MODEL='.($answer->model ?? 'a versioned id such as jev-1.14.0'));
        }

        return self::SUCCESS;
    }

    private static function isAlias(string $model): bool
    {
        return str_ends_with($model, '-latest') || str_ends_with($model, '-preview');
    }
}
