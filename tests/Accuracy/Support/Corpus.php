<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Tests\Accuracy\Support;

use InvalidArgumentException;
use Phoenix1331\JevModeration\Data\Policy;
use Phoenix1331\JevModeration\Enums\ModerationStatus;

/**
 * Loads and validates the labelled samples for a preset.
 */
final class Corpus
{
    /** Statuses the resolver can reach; human statuses never come from Jev. */
    private const array OUTCOMES = [ModerationStatus::Passed, ModerationStatus::Flagged, ModerationStatus::Blocked];

    /**
     * @return list<string>
     */
    public static function presets(): array
    {
        return array_map(fn (string $file): string => basename($file, '.php'), self::files());
    }

    /**
     * @return list<Sample>
     */
    public static function load(Policy $policy): array
    {
        $path = self::directory()."/{$policy->name}.php";

        if (! is_file($path)) {
            throw new InvalidArgumentException("No corpus for policy [{$policy->name}] at {$path}.");
        }

        return self::samples($policy, require $path);
    }

    /**
     * Validate raw corpus entries against the policy they are for.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<Sample>
     */
    public static function samples(Policy $policy, array $entries): array
    {
        $samples = [];
        $ids = [];

        foreach ($entries as $raw) {
            $sample = self::sample($policy, $raw);

            if (isset($ids[$sample->id])) {
                throw new InvalidArgumentException("Corpus [{$policy->name}] repeats the id [{$sample->id}].");
            }

            $ids[$sample->id] = true;
            $samples[] = $sample;
        }

        return $samples;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private static function sample(Policy $policy, array $raw): Sample
    {
        $id = $raw['id'] ?? '?';
        $label = $raw['label'] ?? null;
        $expected = ModerationStatus::tryFrom($raw['expected'] ?? '');
        $acceptable = array_map(fn (string $status): ?ModerationStatus => ModerationStatus::tryFrom($status), $raw['acceptable'] ?? []);

        if (! is_string($label) || ! array_key_exists($label, $policy->choices)) {
            throw new InvalidArgumentException("Corpus [{$policy->name}] sample [{$id}] has a label that is not one of the policy's choices.");
        }

        foreach ([$expected, ...$acceptable] as $status) {
            if (! in_array($status, self::OUTCOMES, true)) {
                throw new InvalidArgumentException("Corpus [{$policy->name}] sample [{$id}] expects something other than passed, flagged or blocked.");
            }
        }

        return new Sample(
            id: $id,
            label: $label,
            expected: $expected,
            acceptable: $acceptable,
            content: $raw['content'],
            context: $raw['context'] ?? null,
            note: $raw['note'] ?? null,
        );
    }

    /**
     * @return list<string>
     */
    private static function files(): array
    {
        $files = glob(self::directory().'/*.php') ?: [];
        sort($files);

        return $files;
    }

    private static function directory(): string
    {
        return dirname(__DIR__).'/Corpus';
    }
}
