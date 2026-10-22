<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Data;

use Phoenix1331\JevModeration\Enums\ModerationStatus;

/**
 * An ad-hoc check from JevModeration::classify(): what the policy decided,
 * and what Jev answered. Nothing is recorded.
 */
final readonly class CheckResult
{
    public function __construct(
        public string $policy,
        public Verdict $verdict,
        public Classification $classification,
    ) {}

    public function status(): ModerationStatus
    {
        return $this->verdict->status;
    }

    public function passed(): bool
    {
        return $this->verdict->status === ModerationStatus::Passed;
    }

    public function flagged(): bool
    {
        return $this->verdict->status === ModerationStatus::Flagged;
    }

    public function blocked(): bool
    {
        return $this->verdict->status === ModerationStatus::Blocked;
    }
}
