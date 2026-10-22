<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Enums;

/**
 * Whether content is visible when its check failed.
 */
enum FailMode: string
{
    /** Visible: an outage never hides content. */
    case Open = 'open';

    /** Hidden: an outage never lets unchecked content through. */
    case Closed = 'closed';
}
