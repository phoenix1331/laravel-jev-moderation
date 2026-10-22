<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;
use Phoenix1331\JevModeration\Models\ModerationDecision;

/**
 * A save needs a check: the pending decision was recorded.
 */
final readonly class ContentPending
{
    use SerializesModels;

    public function __construct(
        public Model $model,
        public ModerationDecision $decision,
    ) {}
}
