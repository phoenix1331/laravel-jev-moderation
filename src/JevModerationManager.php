<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration;

use Closure;
use Phoenix1331\JevModeration\Contracts\ModerationDriver;
use Phoenix1331\JevModeration\Data\CheckResult;
use Phoenix1331\JevModeration\Data\Policy;
use Phoenix1331\JevModeration\Drivers\DriverManager;
use Phoenix1331\JevModeration\Exceptions\DriverException;
use Phoenix1331\JevModeration\Policies\PolicyRegistry;
use Phoenix1331\JevModeration\Policies\ThresholdResolver;

/**
 * The JevModeration facade's target. Every public method here is public API
 * and listed as an @method on the facade (a test keeps them in step).
 */
final readonly class JevModerationManager
{
    public function __construct(
        private DriverManager $drivers,
        private PolicyRegistry $policies,
        private ThresholdResolver $resolver,
    ) {}

    /**
     * Check content against a policy without recording anything, e.g. a
     * chat message before it is broadcast.
     *
     * @param  string|array<string, string>  $content  text, or field name to value
     * @param  string|null  $policy  a policy from config; null uses jev-moderation.policy
     *
     * @throws DriverException when the classifier cannot answer
     */
    public function classify(string|array $content, ?string $policy = null, ?string $context = null): CheckResult
    {
        $policy = $this->policies->get($policy);
        $classification = $this->drivers->driver()->classify($policy->request(is_string($content) ? ['content' => $content] : $content, $context));

        return new CheckResult($policy->name, $this->resolver->resolve($policy, $classification), $classification);
    }

    /**
     * A policy from config; null for the default.
     */
    public function policy(?string $name = null): Policy
    {
        return $this->policies->get($name);
    }

    /**
     * The names of every configured policy.
     *
     * @return list<string>
     */
    public function policies(): array
    {
        return $this->policies->names();
    }

    /**
     * Register a custom classifier, then name it in jev-moderation.driver.
     *
     * @param  Closure(): ModerationDriver  $factory
     */
    public function extend(string $driver, Closure $factory): void
    {
        $this->drivers->extend($driver, $factory);
    }
}
