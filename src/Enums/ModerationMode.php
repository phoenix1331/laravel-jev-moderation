<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Enums;

/**
 * When content becomes visible.
 */
enum ModerationMode: string
{
    /** Visible straight away; hidden once blocked or rejected. */
    case Post = 'post';

    /** Hidden until it passes or a human approves it. */
    case Pre = 'pre';
}
