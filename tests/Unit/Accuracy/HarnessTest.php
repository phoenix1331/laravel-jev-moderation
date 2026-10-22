<?php

declare(strict_types=1);

use Phoenix1331\JevModeration\Data\Classification;
use Phoenix1331\JevModeration\Data\ModerationRequest;
use Phoenix1331\JevModeration\Data\Policy;
use Phoenix1331\JevModeration\Data\Verdict;
use Phoenix1331\JevModeration\Drivers\FakeDriver;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Enums\VerdictReason;
use Phoenix1331\JevModeration\Exceptions\DriverException;
use Phoenix1331\JevModeration\Policies\PolicyRegistry;
use Phoenix1331\JevModeration\Policies\ThresholdResolver;
use Phoenix1331\JevModeration\Tests\Accuracy\Support\AccuracyReport;
use Phoenix1331\JevModeration\Tests\Accuracy\Support\AccuracyResult;
use Phoenix1331\JevModeration\Tests\Accuracy\Support\AccuracyRunner;
use Phoenix1331\JevModeration\Tests\Accuracy\Support\Corpus;
use Phoenix1331\JevModeration\Tests\Accuracy\Support\Sample;

function preset(string $name): Policy
{
    return app(PolicyRegistry::class)->get($name);
}

/**
 * A fake that is certain each sample is its label, so every risky sample is
 * caught and the outcome depends only on the preset's thresholds.
 */
function labelFake(Policy $policy): FakeDriver
{
    $labels = [];

    foreach (Corpus::load($policy) as $sample) {
        $labels[json_encode($sample->content)] = $sample->label;
    }

    return (new FakeDriver)->classifyUsing(fn (ModerationRequest $request): Classification => new Classification(
        choice: $labels[json_encode($request->content)],
        confidence: 1.0,
        probabilities: [$labels[json_encode($request->content)] => 1.0],
        driver: 'fake',
        model: 'fake-1',
        inputTokens: 500,
    ));
}

function sample(ModerationStatus $expected = ModerationStatus::Flagged, array $acceptable = [], string $label = 'harassment'): Sample
{
    return new Sample('s1', $label, $expected, $acceptable, ['body' => 'text']);
}

describe('corpus', function (): void {
    it('has a corpus for every shipped preset and nothing else', function (): void {
        expect(Corpus::presets())->toEqualCanonicalizing(app(PolicyRegistry::class)->names());
    });

    it('stays light: 40 to 60 samples in all', function (): void {
        $total = array_sum(array_map(fn (string $name): int => count(Corpus::load(preset($name))), Corpus::presets()));

        expect($total)->toBeGreaterThanOrEqual(40)->toBeLessThanOrEqual(60);
    });

    it('covers every risky category and includes borderline cases', function (string $name): void {
        $samples = Corpus::load(preset($name));
        $labels = array_unique(array_map(fn (Sample $sample): string => $sample->label, $samples));

        expect(array_diff(array_keys(preset($name)->rules), $labels))->toBe([])
            ->and(array_filter($samples, fn (Sample $sample): bool => $sample->acceptable !== []))->not->toBeEmpty()
            ->and(array_filter($samples, fn (Sample $sample): bool => $sample->expected === ModerationStatus::Passed))->not->toBeEmpty();
    })->with(['community', 'chat', 'strict']);

    it('rejects an invalid sample', function (array $entry, string $message): void {
        expect(fn () => Corpus::samples(preset('community'), [$entry]))->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'label not a choice' => [['id' => 'x', 'label' => 'hate_speech', 'expected' => 'flagged', 'content' => ['body' => 'text']], "Corpus [community] sample [x] has a label that is not one of the policy's choices."],
        'human status expected' => [['id' => 'x', 'label' => 'spam', 'expected' => 'approved', 'content' => ['body' => 'text']], 'expects something other than passed, flagged or blocked'],
        'unknown acceptable status' => [['id' => 'x', 'label' => 'spam', 'expected' => 'flagged', 'acceptable' => ['maybe'], 'content' => ['body' => 'text']], 'expects something other than passed, flagged or blocked'],
    ]);

    it('rejects repeated ids', function (): void {
        $entry = ['id' => 'x', 'label' => 'spam', 'expected' => 'flagged', 'content' => ['body' => 'text']];

        Corpus::samples(preset('community'), [$entry, $entry]);
    })->throws(InvalidArgumentException::class, 'Corpus [community] repeats the id [x].');

    it('throws for a policy with no corpus', function (): void {
        Corpus::load(new Policy('reviews', 'Classify', ['a' => '', 'b' => ''], 'b', []));
    })->throws(InvalidArgumentException::class, 'No corpus for policy [reviews]');
});

describe('runner', function (): void {
    it('catches every labelled sample when the classifier is certain', function (string $name): void {
        $policy = preset($name);
        $results = (new AccuracyRunner(labelFake($policy), new ThresholdResolver))->run($policy, Corpus::load($policy));

        foreach ($results as $result) {
            expect($result->verdict?->status === ModerationStatus::Passed)->toBe($result->sample->label === $policy->safeChoice);
        }
    })->with(['community', 'chat', 'strict']);

    it('retries rate limits, then succeeds', function (): void {
        $waits = [];
        $fake = (new FakeDriver)->pushFailure(DriverException::retryable('busy', 429, retryAfter: 4))->pushFailure()->classifyAs('fine');

        $results = (new AccuracyRunner($fake, new ThresholdResolver, 3, function (int $seconds) use (&$waits): void {
            $waits[] = $seconds;
        }))->run(preset('community'), [sample(ModerationStatus::Passed, label: 'fine')]);

        expect($waits)->toBe([4, 4])
            ->and($results[0]->outcome())->toBe(AccuracyResult::EXACT);
    });

    it('records an error after the last attempt or on a permanent failure', function (DriverException $failure, int $calls): void {
        $fake = (new FakeDriver)->failWith($failure);

        $results = (new AccuracyRunner($fake, new ThresholdResolver, 2, fn (int $seconds) => null))->run(preset('community'), [sample()]);

        expect($results[0]->outcome())->toBe(AccuracyResult::ERROR)
            ->and($results[0]->error)->toBe($failure->getMessage());
        $fake->assertClassifiedTimes($calls);
    })->with([
        'retryable' => [DriverException::retryable('busy'), 2],
        'permanent' => [DriverException::permanent('bad key'), 1],
    ]);
});

describe('outcomes', function (): void {
    function result(Sample $sample, ModerationStatus $actual): AccuracyResult
    {
        return new AccuracyResult($sample, verdict: new Verdict($actual, VerdictReason::Clear, 'harassment', 0.5));
    }

    it('scores exact, acceptable and wrong', function (): void {
        $sample = sample(ModerationStatus::Flagged, [ModerationStatus::Blocked]);

        expect(result($sample, ModerationStatus::Flagged)->outcome())->toBe(AccuracyResult::EXACT)
            ->and(result($sample, ModerationStatus::Blocked)->outcome())->toBe(AccuracyResult::ACCEPTABLE)
            ->and(result($sample, ModerationStatus::Passed)->outcome())->toBe(AccuracyResult::WRONG);
    });

    it('names the kind of mistake', function (): void {
        $harmful = sample(ModerationStatus::Flagged);
        $fine = sample(ModerationStatus::Passed, label: 'fine');

        expect(result($harmful, ModerationStatus::Passed)->missed())->toBeTrue()
            ->and(result($fine, ModerationStatus::Blocked)->wronglyBlocked())->toBeTrue()
            ->and(result($fine, ModerationStatus::Flagged)->overFlagged())->toBeTrue()
            ->and(result(sample(ModerationStatus::Passed, [ModerationStatus::Flagged], 'fine'), ModerationStatus::Flagged)->overFlagged())->toBeFalse();
    });
});

describe('report', function (): void {
    it('summarises each preset, category catch rate and every miss', function (): void {
        $policy = preset('community');
        $results = ['community' => (new AccuracyRunner(labelFake($policy), new ThresholdResolver))->run($policy, Corpus::load($policy))];
        $results['community'][] = new AccuracyResult(sample(), error: 'Jev returned HTTP 529: no details');

        $markdown = AccuracyReport::markdown($results, '2026-10-22');

        expect($markdown)->toContain('Measured 2026-10-22 against live Jev (`fake-1`)')
            ->toContain('| Preset | Samples | Exact |')
            ->toContain('| `community` | 25 |')
            ->toContain('| `community` | `harassment` |')
            ->toContain('| `community` | `s1` | flagged | error | error | Jev returned HTTP 529: no details | - | - |')
            ->not->toContain('—');
    });

    it('says when nothing answered', function (): void {
        expect(AccuracyReport::markdown(['chat' => []], '2026-10-22'))->toContain('(no answers)');
    });
});
