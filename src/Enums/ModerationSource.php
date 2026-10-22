<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Enums;

/**
 * What produced a moderation decision. Stored as its value.
 */
enum ModerationSource: string
{
    /** The observer, on create or update. */
    case Jev = 'jev';

    /** A moderator through approve() or reject(). */
    case Human = 'human';

    /** A user report through flagForReview(). */
    case Report = 'report';

    /** The jev-moderation:rescan command. */
    case Rescan = 'rescan';
}
