<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Policies;

use Phoenix1331\JevModeration\Data\Classification;
use Phoenix1331\JevModeration\Data\Policy;
use Phoenix1331\JevModeration\Data\Verdict;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Enums\VerdictReason;

/**
 * Turns a classification into a status. Every risky category is checked
 * against its own probability, not only the top choice, so a post that is
 * 55% fine and 44% harassment still reaches a human. The strongest action
 * wins (block over flag), then the most likely category. With nothing over
 * a threshold, an answer below the policy's confidence floor is flagged.
 *
 * @internal
 */
final readonly class ThresholdResolver
{
    public function resolve(Policy $policy, Classification $classification): Verdict
    {
        $verdict = null;

        foreach ($policy->rules as $category => $rule) {
            $probability = $classification->probabilityOf($category);

            $candidate = match (true) {
                $rule->blockAbove !== null && $probability >= $rule->blockAbove => new Verdict(ModerationStatus::Blocked, VerdictReason::BlockThreshold, $category, $probability),
                $probability >= $rule->reviewAbove => new Verdict(ModerationStatus::Flagged, VerdictReason::ReviewThreshold, $category, $probability),
                default => null,
            };

            if ($candidate !== null && ($verdict === null || self::outranks($candidate, $verdict))) {
                $verdict = $candidate;
            }
        }

        if ($verdict !== null) {
            return $verdict;
        }

        $top = new Verdict(ModerationStatus::Passed, VerdictReason::Clear, $classification->choice, $classification->probabilityOf($classification->choice));

        if ($policy->uncertainBelow !== null && $classification->confidence < $policy->uncertainBelow) {
            return new Verdict(ModerationStatus::Flagged, VerdictReason::LowConfidence, $top->category, $top->probability);
        }

        return $top;
    }

    private static function outranks(Verdict $candidate, Verdict $current): bool
    {
        if ($candidate->status !== $current->status) {
            return $candidate->status === ModerationStatus::Blocked;
        }

        return $candidate->probability > $current->probability;
    }
}
