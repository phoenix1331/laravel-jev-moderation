<?php

declare(strict_types=1);

use Phoenix1331\JevModeration\Data\CategoryRule;

it('keeps the review and block thresholds', function (): void {
    $rule = new CategoryRule('spam', 0.5, 0.9);

    expect($rule->category)->toBe('spam')
        ->and($rule->reviewAbove)->toBe(0.5)
        ->and($rule->blockAbove)->toBe(0.9);
});

it('never blocks without a block threshold', function (): void {
    expect((new CategoryRule('personal_data', 0.4))->blockAbove)->toBeNull();
});

it('accepts equal review and block thresholds and the bounds 0 and 1', function (): void {
    expect((new CategoryRule('hate', 0.0, 0.0))->blockAbove)->toBe(0.0)
        ->and((new CategoryRule('hate', 1.0, 1.0))->reviewAbove)->toBe(1.0);
});

it('rejects thresholds outside 0 to 1', function (float $review, ?float $block, string $message): void {
    expect(fn () => new CategoryRule('spam', $review, $block))->toThrow(InvalidArgumentException::class, $message);
})->with([
    [-0.1, null, 'The review_above threshold for [spam] must be between 0 and 1, -0.1 given.'],
    [1.5, null, 'The review_above threshold for [spam] must be between 0 and 1, 1.5 given.'],
    [0.5, 1.2, 'The block_above threshold for [spam] must be between 0 and 1, 1.2 given.'],
]);

it('rejects a block threshold below the review threshold', function (): void {
    new CategoryRule('spam', 0.9, 0.5);
})->throws(InvalidArgumentException::class, 'The block_above threshold for [spam] (0.5) must not be below its review_above threshold (0.9).');
