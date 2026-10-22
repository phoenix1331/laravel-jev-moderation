<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Data;

use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Enums\VerdictReason;

/**
 * The status a classification earns under a policy, and the category that
 * decided it: the category over a threshold, or the top choice otherwise.
 */
final readonly class Verdict
{
    public function __construct(
        public ModerationStatus $status,
        public VerdictReason $reason,
        public string $category,
        public float $probability,
    ) {}
}
