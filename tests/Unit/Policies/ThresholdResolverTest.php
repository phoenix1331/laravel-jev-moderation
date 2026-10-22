<?php

declare(strict_types=1);

use Phoenix1331\JevModeration\Data\CategoryRule;
use Phoenix1331\JevModeration\Data\Classification;
use Phoenix1331\JevModeration\Data\Policy;
use Phoenix1331\JevModeration\Data\Verdict;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Enums\VerdictReason;
use Phoenix1331\JevModeration\Policies\ThresholdResolver;

/**
 * The shipped community thresholds, on the recorded choices.
 */
function communityPolicy(?float $uncertainBelow = 0.3): Policy
{
    return new Policy('community', 'Classify', array_fill_keys(['spam', 'harassment', 'hate', 'personal_data', 'off_topic', 'fine'], ''), 'fine', [
        'spam' => new CategoryRule('spam', 0.5, 0.9),
        'harassment' => new CategoryRule('harassment', 0.4, 0.95),
        'hate' => new CategoryRule('hate', 0.3, 0.95),
        'personal_data' => new CategoryRule('personal_data', 0.4),
        'off_topic' => new CategoryRule('off_topic', 0.8),
    ], $uncertainBelow);
}

/**
 * @param  array<string, float>  $probabilities
 */
function answer(array $probabilities, ?float $confidence = null): Classification
{
    arsort($probabilities);

    return new Classification((string) array_key_first($probabilities), $confidence ?? 0.9, $probabilities, 'fake');
}

function verdictFor(Classification $classification, ?Policy $policy = null): Verdict
{
    return (new ThresholdResolver)->resolve($policy ?? communityPolicy(), $classification);
}

describe('recorded answers', function (): void {
    it('passes a clean post', function (): void {
        $verdict = verdictFor(jevClassification('systemone-fine'));

        expect($verdict->status)->toBe(ModerationStatus::Passed)
            ->and($verdict->reason)->toBe(VerdictReason::Clear)
            ->and($verdict->category)->toBe('fine')
            ->and($verdict->probability)->toBe(1.0);
    });

    it('blocks certain spam', function (): void {
        $verdict = verdictFor(jevClassification('systemone-spam'));

        expect($verdict->status)->toBe(ModerationStatus::Blocked)
            ->and($verdict->reason)->toBe(VerdictReason::BlockThreshold)
            ->and($verdict->category)->toBe('spam');
    });

    it('flags the borderline post even though fine was the top choice', function (): void {
        $verdict = verdictFor(jevClassification('systemone-borderline'));

        expect($verdict->status)->toBe(ModerationStatus::Flagged)
            ->and($verdict->reason)->toBe(VerdictReason::ReviewThreshold)
            ->and($verdict->category)->toBe('harassment')
            ->and($verdict->probability)->toBe(0.44);
    });
});

describe('thresholds', function (): void {
    it('counts a probability equal to a threshold as above it', function (float $spam, ModerationStatus $status): void {
        expect(verdictFor(answer(['spam' => $spam, 'fine' => 1 - $spam]))->status)->toBe($status);
    })->with([
        'just under review' => [0.4999, ModerationStatus::Passed],
        'equal to review' => [0.5, ModerationStatus::Flagged],
        'just under block' => [0.8999, ModerationStatus::Flagged],
        'equal to block' => [0.9, ModerationStatus::Blocked],
    ]);

    it('never blocks a category without a block threshold', function (): void {
        $verdict = verdictFor(answer(['personal_data' => 1.0]));

        expect($verdict->status)->toBe(ModerationStatus::Flagged)
            ->and($verdict->category)->toBe('personal_data');
    });

    it('lets a block on one category beat a more likely flag on another', function (): void {
        $policy = new Policy('custom', 'Classify', ['threat' => '', 'rude' => '', 'fine' => ''], 'fine', [
            'threat' => new CategoryRule('threat', 0.1, 0.3),
            'rude' => new CategoryRule('rude', 0.4),
        ]);

        $verdict = verdictFor(answer(['threat' => 0.35, 'rude' => 0.6, 'fine' => 0.05]), $policy);

        expect($verdict->status)->toBe(ModerationStatus::Blocked)
            ->and($verdict->category)->toBe('threat')
            ->and($verdict->probability)->toBe(0.35);
    });

    it('names the most likely category when several reach the same action', function (): void {
        $verdict = verdictFor(answer(['hate' => 0.35, 'harassment' => 0.45, 'fine' => 0.2]));

        expect($verdict->status)->toBe(ModerationStatus::Flagged)
            ->and($verdict->category)->toBe('harassment');
    });

    it('ignores categories without a rule, even as the top choice', function (): void {
        $policy = new Policy('custom', 'Classify', ['spam' => '', 'joke' => '', 'fine' => ''], 'fine', [
            'spam' => new CategoryRule('spam', 0.5),
        ]);

        $verdict = verdictFor(answer(['joke' => 0.99, 'spam' => 0.01]), $policy);

        expect($verdict->status)->toBe(ModerationStatus::Passed)
            ->and($verdict->category)->toBe('joke');
    });

    it('passes a likely category that is still under its threshold', function (): void {
        expect(verdictFor(answer(['off_topic' => 0.7, 'fine' => 0.3]))->status)->toBe(ModerationStatus::Passed);
    });

    it('reads 0 for a category the answer left out', function (): void {
        expect(verdictFor(new Classification('fine', 1.0, [], 'fake'))->probability)->toBe(0.0);
    });
});

describe('confidence floor', function (): void {
    it('flags an undecided answer that reached no threshold', function (): void {
        $verdict = verdictFor(answer(['fine' => 0.38, 'off_topic' => 0.3, 'harassment' => 0.2, 'spam' => 0.12], 0.25));

        expect($verdict->status)->toBe(ModerationStatus::Flagged)
            ->and($verdict->reason)->toBe(VerdictReason::LowConfidence)
            ->and($verdict->category)->toBe('fine')
            ->and($verdict->probability)->toBe(0.38);
    });

    it('does not flag at exactly the floor', function (): void {
        expect(verdictFor(answer(['fine' => 0.5, 'off_topic' => 0.5], 0.3))->status)->toBe(ModerationStatus::Passed);
    });

    it('lets a threshold decide before the floor', function (): void {
        $verdict = verdictFor(answer(['spam' => 0.95, 'fine' => 0.05], 0.1));

        expect($verdict->status)->toBe(ModerationStatus::Blocked)
            ->and($verdict->reason)->toBe(VerdictReason::BlockThreshold);
    });

    it('passes low confidence when the policy has no floor', function (): void {
        expect(verdictFor(answer(['fine' => 0.38, 'off_topic' => 0.3, 'spam' => 0.32], 0.05), communityPolicy(null))->status)
            ->toBe(ModerationStatus::Passed);
    });
});
