<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Enums;

/**
 * The outcome recorded on a moderation decision. Stored as its value.
 */
enum ModerationStatus: string
{
    /** Waiting for a classification. */
    case Pending = 'pending';

    /** The check gave up (retries used up, or a permanent error); the fail setting decides visibility. */
    case Failed = 'failed';

    /** Below every review threshold. */
    case Passed = 'passed';

    /** Waiting for a human. */
    case Flagged = 'flagged';

    /** Actioned automatically. */
    case Blocked = 'blocked';

    /** A human overrode a flag or block. */
    case Approved = 'approved';

    /** A human confirmed a flag or block. */
    case Rejected = 'rejected';

    /**
     * Whether a person made this decision rather than Jev.
     */
    public function isHuman(): bool
    {
        return $this === self::Approved || $this === self::Rejected;
    }
}
