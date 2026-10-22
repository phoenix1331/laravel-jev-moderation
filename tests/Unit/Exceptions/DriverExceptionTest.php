<?php

declare(strict_types=1);

use Phoenix1331\JevModeration\Exceptions\DriverException;

it('marks transient failures as retryable', function (): void {
    $previous = new RuntimeException('cURL error 28');

    $exception = DriverException::retryable('Jev is overloaded.', 529, 'req_1', $previous, 7);

    expect($exception->retryable)->toBeTrue()
        ->and($exception->getMessage())->toBe('Jev is overloaded.')
        ->and($exception->status)->toBe(529)
        ->and($exception->requestId)->toBe('req_1')
        ->and($exception->getPrevious())->toBe($previous)
        ->and($exception->retryAfter)->toBe(7);
});

it('marks other failures as permanent', function (): void {
    $exception = DriverException::permanent('Jev did not accept the API key.', 401);

    expect($exception->retryable)->toBeFalse()
        ->and($exception->status)->toBe(401)
        ->and($exception->requestId)->toBeNull()
        ->and($exception->getPrevious())->toBeNull()
        ->and($exception->retryAfter)->toBeNull();
});
