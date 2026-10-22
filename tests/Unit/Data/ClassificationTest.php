<?php

declare(strict_types=1);

use Phoenix1331\JevModeration\Data\Classification;

/**
 * @param  array<string, float>  $probabilities
 */
function classification(string $choice = 'fine', float $confidence = 0.46, array $probabilities = ['fine' => 0.55, 'harassment' => 0.44, 'off_topic' => 0.01]): Classification
{
    return new Classification($choice, $confidence, $probabilities, 'jev', 'jev-1.14.0', 490, 'req_1', 380);
}

it('carries the answer and where it came from', function (): void {
    $result = classification();

    expect($result->choice)->toBe('fine')
        ->and($result->confidence)->toBe(0.46)
        ->and($result->driver)->toBe('jev')
        ->and($result->model)->toBe('jev-1.14.0')
        ->and($result->inputTokens)->toBe(490)
        ->and($result->requestId)->toBe('req_1')
        ->and($result->latencyMs)->toBe(380);
});

it('reads the probability of any choice, 0 when absent', function (): void {
    $result = classification();

    expect($result->probabilityOf('harassment'))->toBe(0.44)
        ->and($result->probabilityOf('hate'))->toBe(0.0);
});

it('defaults the optional details', function (): void {
    $result = new Classification('spam', 1.0, ['spam' => 1.0], 'fake');

    expect($result->model)->toBeNull()
        ->and($result->inputTokens)->toBeNull()
        ->and($result->requestId)->toBeNull()
        ->and($result->latencyMs)->toBe(0);
});

it('accepts the bounds 0 and 1', function (): void {
    expect(classification(confidence: 0.0, probabilities: ['fine' => 1.0, 'spam' => 0.0])->confidence)->toBe(0.0)
        ->and(classification(confidence: 1.0)->confidence)->toBe(1.0);
});

it('needs a choice', function (): void {
    classification(choice: '');
})->throws(InvalidArgumentException::class, 'needs a choice');

it('rejects a confidence outside 0 to 1', function (float $confidence): void {
    classification(confidence: $confidence);
})->with([-0.01, 1.01])->throws(InvalidArgumentException::class, 'The confidence must be between 0 and 1');

it('rejects a probability outside 0 to 1', function (): void {
    classification(probabilities: ['fine' => 1.2]);
})->throws(InvalidArgumentException::class, 'The probability of [fine] must be between 0 and 1, 1.2 given.');
