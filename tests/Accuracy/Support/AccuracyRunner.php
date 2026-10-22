<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Tests\Accuracy\Support;

use Closure;
use Phoenix1331\JevModeration\Contracts\ModerationDriver;
use Phoenix1331\JevModeration\Data\Policy;
use Phoenix1331\JevModeration\Exceptions\DriverException;
use Phoenix1331\JevModeration\Policies\ThresholdResolver;

/**
 * Sends each sample through a driver and the resolver, one at a time,
 * retrying rate limits and overload a few times before recording an error.
 */
final readonly class AccuracyRunner
{
    /**
     * @param  Closure(int): void  $sleep  waits this many seconds; swapped out in tests
     */
    public function __construct(
        private ModerationDriver $driver,
        private ThresholdResolver $resolver,
        private int $attempts = 3,
        private ?Closure $sleep = null,
    ) {}

    /**
     * @param  list<Sample>  $samples
     * @return list<AccuracyResult>
     */
    public function run(Policy $policy, array $samples): array
    {
        return array_map(fn (Sample $sample): AccuracyResult => $this->one($policy, $sample), $samples);
    }

    private function one(Policy $policy, Sample $sample): AccuracyResult
    {
        $request = $policy->request($sample->content, $sample->context);

        for ($attempt = 1; ; $attempt++) {
            try {
                $classification = $this->driver->classify($request);

                return new AccuracyResult($sample, $classification, $this->resolver->resolve($policy, $classification));
            } catch (DriverException $e) {
                if (! $e->retryable || $attempt >= $this->attempts) {
                    return new AccuracyResult($sample, error: $e->getMessage());
                }

                ($this->sleep ?? sleep(...))($e->retryAfter ?? 2 * $attempt);
            }
        }
    }
}
