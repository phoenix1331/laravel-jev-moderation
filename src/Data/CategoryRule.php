<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Data;

use InvalidArgumentException;

/**
 * What to do when one category is likely. Compared with that category's
 * probability; a probability equal to a threshold counts as above it.
 */
final readonly class CategoryRule
{
    /**
     * @param  float  $reviewAbove  flag for a human at or above this probability
     * @param  float|null  $blockAbove  block automatically at or above this probability; null never blocks
     */
    public function __construct(
        public string $category,
        public float $reviewAbove,
        public ?float $blockAbove = null,
    ) {
        foreach (['review_above' => $reviewAbove, 'block_above' => $blockAbove] as $key => $threshold) {
            if ($threshold !== null && ($threshold < 0.0 || $threshold > 1.0)) {
                throw new InvalidArgumentException(sprintf('The %s threshold for [%s] must be between 0 and 1, %s given.', $key, $category, $threshold));
            }
        }

        if ($blockAbove !== null && $blockAbove < $reviewAbove) {
            throw new InvalidArgumentException(sprintf(
                'The block_above threshold for [%s] (%s) must not be below its review_above threshold (%s).',
                $category,
                $blockAbove,
                $reviewAbove,
            ));
        }
    }
}
