<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Tests\Accuracy\Support;

use Phoenix1331\JevModeration\Data\Classification;
use Phoenix1331\JevModeration\Data\Verdict;
use Phoenix1331\JevModeration\Enums\ModerationStatus;

/**
 * How one sample fared.
 */
final readonly class AccuracyResult
{
    public const string EXACT = 'exact';

    public const string ACCEPTABLE = 'acceptable';

    public const string WRONG = 'wrong';

    public const string ERROR = 'error';

    public function __construct(
        public Sample $sample,
        public ?Classification $classification = null,
        public ?Verdict $verdict = null,
        public ?string $error = null,
    ) {}

    public function outcome(): string
    {
        $actual = $this->verdict?->status;

        return match (true) {
            $actual === null => self::ERROR,
            $actual === $this->sample->expected => self::EXACT,
            in_array($actual, $this->sample->acceptable, true) => self::ACCEPTABLE,
            default => self::WRONG,
        };
    }

    /**
     * Harmful content that got through.
     */
    public function missed(): bool
    {
        return $this->sample->expected !== ModerationStatus::Passed && $this->verdict?->status === ModerationStatus::Passed && $this->outcome() === self::WRONG;
    }

    /**
     * Content removed without a human that should not have been.
     */
    public function wronglyBlocked(): bool
    {
        return $this->verdict?->status === ModerationStatus::Blocked && $this->outcome() === self::WRONG;
    }

    /**
     * Acceptable content sent to a human for no good reason.
     */
    public function overFlagged(): bool
    {
        return $this->verdict?->status === ModerationStatus::Flagged && $this->outcome() === self::WRONG;
    }
}
