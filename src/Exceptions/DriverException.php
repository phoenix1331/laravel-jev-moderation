<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A classifier could not answer. Retryable failures (rate limits, overload,
 * timeouts) are tried again with backoff; permanent ones (bad key, invalid
 * policy) fail the check at once.
 */
final class DriverException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable,
        public readonly ?int $status = null,
        public readonly ?string $requestId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function retryable(string $message, ?int $status = null, ?string $requestId = null, ?Throwable $previous = null): self
    {
        return new self($message, true, $status, $requestId, $previous);
    }

    public static function permanent(string $message, ?int $status = null, ?string $requestId = null, ?Throwable $previous = null): self
    {
        return new self($message, false, $status, $requestId, $previous);
    }
}
