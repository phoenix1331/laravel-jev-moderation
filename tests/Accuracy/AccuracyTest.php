<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Phoenix1331\JevModeration\Drivers\DriverManager;
use Phoenix1331\JevModeration\Policies\PolicyRegistry;
use Phoenix1331\JevModeration\Policies\ThresholdResolver;
use Phoenix1331\JevModeration\Tests\Accuracy\Support\AccuracyReport;
use Phoenix1331\JevModeration\Tests\Accuracy\Support\AccuracyResult;
use Phoenix1331\JevModeration\Tests\Accuracy\Support\AccuracyRunner;
use Phoenix1331\JevModeration\Tests\Accuracy\Support\Corpus;

/*
 * Sends every corpus sample to live Jev and writes docs/accuracy.md.
 * Run with: JEV_API_KEY=... composer test:accuracy
 * Optional: JEV_MODEL to measure a pinned version instead of jev-latest.
 * About 500 input tokens per sample; skipped without a key, never in CI.
 */

/** @var array<string, list<AccuracyResult>> $results */
$results = [];

afterAll(function () use (&$results): void {
    if ($results === []) {
        return;
    }

    file_put_contents(dirname(__DIR__, 2).'/docs/accuracy.md', AccuracyReport::markdown($results, date('Y-m-d')));
});

foreach (Corpus::presets() as $preset) {
    it("measures the {$preset} preset against live Jev", function () use ($preset, &$results): void {
        $key = getenv('JEV_API_KEY');

        if (! is_string($key) || $key === '') {
            $this->markTestSkipped('Set JEV_API_KEY to run the accuracy suite.');
        }

        Http::allowStrayRequests();
        config([
            'jev-moderation.drivers.jev.key' => $key,
            'jev-moderation.drivers.jev.model' => getenv('JEV_MODEL') ?: 'jev-latest',
            'jev-moderation.drivers.jev.timeout' => 30,
        ]);

        $policy = app(PolicyRegistry::class)->get($preset);
        $runner = new AccuracyRunner(app(DriverManager::class)->driver('jev'), app(ThresholdResolver::class));

        $results[$preset] = $runner->run($policy, Corpus::load($policy));

        expect($results[$preset])->not->toBeEmpty();
    });
}
