<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Exceptions;

use LogicException;

/**
 * Thrown when code tries to change or remove a moderation decision. Record
 * a new decision instead: the history is the audit trail.
 */
final class DecisionsAreAppendOnly extends LogicException
{
    public static function cannotUpdate(): self
    {
        return new self('Moderation decisions are append-only and cannot be updated. Record a new decision instead.');
    }

    public static function cannotDelete(): self
    {
        return new self('Moderation decisions are append-only and cannot be deleted.');
    }
}
