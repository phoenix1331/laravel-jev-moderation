<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;
use Phoenix1331\JevModeration\Models\ModerationDecision;

/**
 * A moderator approved or rejected the content; the decision says which.
 */
final readonly class ReviewResolved
{
    use SerializesModels;

    public function __construct(
        public Model $model,
        public ModerationDecision $decision,
    ) {}
}
