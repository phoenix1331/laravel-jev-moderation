<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Enums;

/**
 * Why the resolver reached its status.
 */
enum VerdictReason: string
{
    /** No category reached a threshold and the answer was confident enough. */
    case Clear = 'clear';

    /** A category reached its review_above threshold. */
    case ReviewThreshold = 'review_threshold';

    /** A category reached its block_above threshold. */
    case BlockThreshold = 'block_threshold';

    /** Below the policy's uncertain_below confidence floor. */
    case LowConfidence = 'low_confidence';
}
