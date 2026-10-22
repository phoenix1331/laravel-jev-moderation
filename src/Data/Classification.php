<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Data;

use InvalidArgumentException;

/**
 * A classifier's answer: the top choice, its confidence and the probability
 * of every choice. Thresholds read probabilities; confidence (how sharp the
 * distribution is) only drives the policy's uncertainty floor.
 */
final readonly class Classification
{
    /**
     * @param  array<string, float>  $probabilities  choice name to probability, 0 to 1
     * @param  string|null  $model  the versioned model that answered, e.g. jev-1.14.0
     * @param  bool  $truncated  whether content or context was trimmed to fit the context budget
     */
    public function __construct(
        public string $choice,
        public float $confidence,
        public array $probabilities,
        public string $driver,
        public ?string $model = null,
        public ?int $inputTokens = null,
        public ?string $requestId = null,
        public int $latencyMs = 0,
        public bool $truncated = false,
    ) {
        if ($choice === '') {
            throw new InvalidArgumentException('A classification needs a choice.');
        }

        self::assertUnit('confidence', $confidence);

        foreach ($probabilities as $name => $probability) {
            self::assertUnit("probability of [{$name}]", $probability);
        }
    }

    /**
     * The probability of one choice; 0 when the classifier did not return it.
     */
    public function probabilityOf(string $choice): float
    {
        return $this->probabilities[$choice] ?? 0.0;
    }

    private static function assertUnit(string $label, float $value): void
    {
        if ($value < 0.0 || $value > 1.0) {
            throw new InvalidArgumentException(sprintf('The %s must be between 0 and 1, %s given.', $label, $value));
        }
    }
}
