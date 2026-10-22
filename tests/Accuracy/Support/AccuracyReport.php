<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Tests\Accuracy\Support;

use Phoenix1331\JevModeration\Enums\ModerationStatus;

/**
 * Renders results as docs/accuracy.md.
 */
final class AccuracyReport
{
    /**
     * @param  array<string, list<AccuracyResult>>  $results  preset to results
     */
    public static function markdown(array $results, string $date): string
    {
        $lines = [
            '# Accuracy',
            '',
            "Measured {$date} against live Jev (".self::models($results).') with `composer test:accuracy`. Synthetic labelled posts in `tests/Accuracy/Corpus/`; "acceptable" means not the ideal outcome but not wrong (a rude but fair post being flagged, say).',
            '',
            '| Preset | Samples | Exact | Exact or acceptable | Missed harmful | Wrongly blocked | Over-flagged | Flagged share | Errors | Input tokens |',
            '|---|---|---|---|---|---|---|---|---|---|',
        ];

        foreach ($results as $preset => $rows) {
            $count = count($rows);
            $exact = self::count($rows, fn (AccuracyResult $r): bool => $r->outcome() === AccuracyResult::EXACT);
            $acceptable = self::count($rows, fn (AccuracyResult $r): bool => $r->outcome() === AccuracyResult::ACCEPTABLE);

            $lines[] = sprintf(
                '| `%s` | %d | %s | %s | %d | %d | %d | %s | %d | %d |',
                $preset,
                $count,
                self::percent($exact, $count),
                self::percent($exact + $acceptable, $count),
                self::count($rows, fn (AccuracyResult $r): bool => $r->missed()),
                self::count($rows, fn (AccuracyResult $r): bool => $r->wronglyBlocked()),
                self::count($rows, fn (AccuracyResult $r): bool => $r->overFlagged()),
                self::percent(self::count($rows, fn (AccuracyResult $r): bool => $r->verdict?->status === ModerationStatus::Flagged), $count),
                self::count($rows, fn (AccuracyResult $r): bool => $r->outcome() === AccuracyResult::ERROR),
                array_sum(array_map(fn (AccuracyResult $r): int => $r->classification?->inputTokens ?? 0, $rows)),
            );
        }

        $lines = [...$lines, '', '## Caught by category', '', 'Share of samples labelled with a risky category that were flagged or blocked.', '', '| Preset | Category | Samples | Caught |', '|---|---|---|---|'];

        foreach ($results as $preset => $rows) {
            $byLabel = [];

            foreach ($rows as $row) {
                if ($row->sample->expected !== ModerationStatus::Passed) {
                    $byLabel[$row->sample->label][] = $row;
                }
            }

            ksort($byLabel);

            foreach ($byLabel as $label => $labelled) {
                $caught = self::count($labelled, fn (AccuracyResult $r): bool => in_array($r->verdict?->status, [ModerationStatus::Flagged, ModerationStatus::Blocked], true));
                $lines[] = sprintf('| `%s` | `%s` | %d | %s |', $preset, $label, count($labelled), self::percent($caught, count($labelled)));
            }
        }

        $lines = [...$lines, '', '## Not exact', '', '| Preset | Sample | Expected | Actual | Outcome | Deciding category | Probability | Confidence |', '|---|---|---|---|---|---|---|---|'];

        foreach ($results as $preset => $rows) {
            foreach ($rows as $row) {
                if ($row->outcome() === AccuracyResult::EXACT) {
                    continue;
                }

                $lines[] = sprintf(
                    '| `%s` | `%s` | %s | %s | %s | %s | %s | %s |',
                    $preset,
                    $row->sample->id,
                    $row->sample->expected->value,
                    $row->verdict?->status->value ?? 'error',
                    $row->outcome(),
                    $row->verdict === null ? str_replace('|', '/', (string) $row->error) : "`{$row->verdict->category}` ({$row->verdict->reason->value})",
                    $row->verdict === null ? '-' : number_format($row->verdict->probability, 2),
                    $row->classification === null ? '-' : number_format($row->classification->confidence, 2),
                );
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, list<AccuracyResult>>  $results
     */
    private static function models(array $results): string
    {
        $models = [];

        foreach ($results as $rows) {
            foreach ($rows as $row) {
                if ($row->classification?->model !== null) {
                    $models[$row->classification->model] = true;
                }
            }
        }

        ksort($models);

        return $models === [] ? 'no answers' : implode(', ', array_map(fn (string $model): string => "`{$model}`", array_keys($models)));
    }

    /**
     * @param  list<AccuracyResult>  $rows
     * @param  callable(AccuracyResult): bool  $test
     */
    private static function count(array $rows, callable $test): int
    {
        return count(array_filter($rows, $test));
    }

    private static function percent(int $part, int $whole): string
    {
        return $whole === 0 ? '-' : round($part / $whole * 100).'%';
    }
}
